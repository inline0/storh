#!/usr/bin/env node
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import * as Cli from "effect/cli/Command";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { attempt, fail, main } from "./lib/cli.ts";
import { run } from "./lib/command.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const php = process.env.PHP_BINARY || "php";
export const metrics = ["doc.put", "doc.index_build", "doc.bulk_put", "doc.jsonl_import", "log.append", "log.compact", "log.bulk_append", "queue.enqueue", "queue.claim", "queue.complete", "queue.bulk_enqueue", "recovery.recover", "cache.cold", "cache.warm", "uuid.monotonic", "mirror.push", "mirror.rebuild"];
export const benchmarkCall = (dataset: number, engine: string, output: string, cacheValidation = "stat", memoryLimit = "") => ({
  command: php,
  cwd: root,
  args: [...(memoryLimit ? ["-d", `memory_limit=${memoryLimit}`] : []), join(root, "bench/bench.php"), `--dataset=${dataset}`, `--engine=${engine}`, `--output=${output}`, `--cache-validation=${cacheValidation}`],
});
export const runCi = Effect.gen(function* () {
  const fs = yield* FileSystem.FileSystem;
  const output = join(root, "build/bench-ci-1k-all.json");
  yield* fs.makeDirectory(dirname(output), { recursive: true });
  yield* run({ ...benchmarkCall(1000, "all", output), inherit: true });
  yield* run({ command: php, cwd: root, inherit: true, args: [join(root, "bench/gate.php"), join(root, "bench/baselines/ci-1k-all.json"), output, `--threshold=${process.env.STORH_BENCH_CI_THRESHOLD || "3000"}`, ...metrics.map((metric) => `--metric=${metric}`)] });
});
export const stats = (input: readonly number[]) => {
  if (input.length === 0) throw new Error("Cannot summarize an empty benchmark series.");
  const values = [...input].sort((a, b) => a - b);
  const runs = values.length;
  const middle = Math.floor(runs / 2);
  return { median: runs % 2 === 1 ? values[middle]! : (values[middle - 1]! + values[middle]!) / 2, min: values[0]!, max: values[runs - 1]!, mean: values.reduce((a, b) => a + b, 0) / runs, runs };
};
const commonFlags = {
  dataset: Flag.Int("dataset").pipe(Flag.withDefault(1000)),
  engine: Flag.String("engine").pipe(Flag.withDefault("all")),
  cacheValidation: Flag.Literals("cache-validation", ["stat", "hash", "trust"]).pipe(Flag.withDefault("stat")),
  memoryLimit: Flag.String("memory-limit").pipe(Flag.withDefault("")),
};
const repeat = Cli.make("repeat", {
  ...commonFlags,
  repeat: Flag.Int("repeat").pipe(Flag.withDefault(5)),
  output: Flag.String("output").pipe(Flag.withDefault(join(root, "build/bench-repeat-current.json"))),
}, (input) => Effect.scoped(Effect.gen(function* () {
  const fs = yield* FileSystem.FileSystem;
  const directory = yield* fs.makeTempDirectoryScoped({ prefix: "storh-repeat-" });
  const series: Record<string, Record<string, number[]>> = {};
  const count = Math.max(1, input.repeat);
  for (let index = 0; index < count; index++) {
    const path = join(directory, `${index}.json`);
    yield* run(benchmarkCall(Math.max(1, input.dataset), input.engine, path, input.cacheValidation, input.memoryLimit));
    const text = yield* fs.readFileString(path);
    const report = yield* attempt(() => JSON.parse(text) as { results: Record<string, Record<string, unknown>> });
    for (const [engine, items] of Object.entries(report.results)) {
      for (const [metric, seconds] of Object.entries(items)) {
        if (typeof seconds === "number") ((series[engine] ??= {})[metric] ??= []).push(seconds);
      }
    }
  }
  const summary = Object.fromEntries(Object.entries(series).map(([engine, items]) => [engine, Object.fromEntries(Object.entries(items).map(([metric, values]) => [metric, stats(values)]))]));
  const results = Object.fromEntries(Object.entries(summary).map(([engine, items]) => [engine, Object.fromEntries(Object.entries(items).map(([metric, value]) => [metric, value.median]))]));
  const version = yield* run({ command: php, args: ["-r", "echo PHP_VERSION;"] });
  const report = { dataset: Math.max(1, input.dataset), engine: input.engine, php: version.stdout, time: new Date().toISOString(), cacheValidation: input.cacheValidation, repeat: count, memoryLimit: input.memoryLimit || null, results, summary };
  yield* fs.makeDirectory(dirname(resolve(input.output)), { recursive: true });
  yield* fs.writeFileString(input.output, JSON.stringify(report, null, 4));
  yield* Effect.sync(() => {
    console.log(`storh bench repeat=${count} dataset=${report.dataset}${input.memoryLimit ? ` memory_limit=${input.memoryLimit}` : ""} output=${input.output}`);
    for (const [engine, items] of Object.entries(summary)) {
      console.log(`[${engine}]`);
      for (const [metric, value] of Object.entries(items)) console.log(`  ${metric.padEnd(28)} median ${value.median.toFixed(6)}s min ${value.min.toFixed(6)}s max ${value.max.toFixed(6)}s mean ${value.mean.toFixed(6)}s`);
    }
  });
})));
export const stringList = (text: string) => [...new Set(text.split(",").map((part) => part.trim()).filter(Boolean))];
const range = Cli.make("range", {
  datasets: Flag.String("datasets").pipe(Flag.withDefault("1000,10000,50000,100000")),
  engines: Flag.String("engines").pipe(Flag.withDefault("doc,log,queue,recovery,cache,filter")),
  outputDir: Flag.String("output-dir").pipe(Flag.withDefault(join(root, "build/bench-ranges"))),
  cacheValidations: Flag.String("cache-validations").pipe(Flag.withDefault("hash,stat,trust")),
  memoryLimit: commonFlags.memoryLimit,
}, (input) => Effect.gen(function* () {
  const fs = yield* FileSystem.FileSystem;
  const datasets = [...new Set(input.datasets.split(",").map((value) => Math.max(1, Number.parseInt(value, 10) || 0)))].sort((a, b) => a - b);
  const requested = stringList(input.engines);
  const engines = requested.includes("all") ? ["doc", "log", "queue", "recovery", "cache", "filter"] : requested;
  const modes = stringList(input.cacheValidations);
  if (modes.some((mode) => !["stat", "hash", "trust"].includes(mode))) return yield* fail("Invalid cache validation mode.", 2);
  yield* fs.makeDirectory(input.outputDir, { recursive: true });
  for (const dataset of datasets) for (const engine of engines) for (const mode of engine === "cache" ? modes : ["stat"]) {
    const path = join(input.outputDir, `bench-${dataset}-${engine}${engine === "cache" ? `-${mode}` : ""}.json`);
    const call = benchmarkCall(dataset, engine, path, mode, input.memoryLimit);
    yield* Effect.sync(() => console.log(`$ ${[call.command, ...call.args].map((value) => JSON.stringify(value)).join(" ")}`));
    yield* run({ ...call, inherit: true });
  }
}));
export const command = Cli.make("bench").pipe(Cli.withSubcommands([Cli.make("ci", {}, () => runCi), repeat, range]));
if (import.meta.main) main(command);
