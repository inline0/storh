import assert from "node:assert/strict";
import { test } from "node:test";
import * as Effect from "effect/Effect";
import { benchmarkCall, metrics, runCi, stats, stringList } from "../bench.ts";
import { Command, CommandFailure, type CommandCall } from "../lib/command.ts";
import { runtimeLayer } from "../lib/runtime.ts";

test("CI gates the fresh report and passes every tracked metric", async () => {
  const calls: CommandCall[] = [];
  await Effect.runPromise(runCi.pipe(Effect.provideService(Command, { run: (call) => { calls.push(call); return Effect.succeed({ code: 0, stdout: "", stderr: "" }); } }), Effect.provide(runtimeLayer)));
  assert.equal(calls.length, 2);
  assert.ok(calls[0]!.args!.includes("--dataset=1000"));
  const report = calls[0]!.args!.find((value) => value.startsWith("--output="))!.slice("--output=".length);
  assert.ok(calls[1]!.args!.includes(report));
  for (const metric of metrics) assert.ok(calls[1]!.args!.includes(`--metric=${metric}`));
});

test("failed benchmark prevents the comparison gate", async () => {
  let count = 0;
  await Effect.runPromise(runCi.pipe(Effect.provideService(Command, { run: () => { count++; return Effect.fail(new CommandFailure({ code: 7, message: "failed" })); } }), Effect.flip, Effect.provide(runtimeLayer)));
  assert.equal(count, 1);
});

test("repeated benchmark statistics preserve even and odd medians", () => {
  assert.deepEqual(stats([4, 1, 3, 2]), { median: 2.5, min: 1, max: 4, mean: 2.5, runs: 4 });
  assert.equal(stats([9, 1, 3]).median, 3);
  assert.deepEqual(stringList("doc, cache,doc,,queue"), ["doc", "cache", "queue"]);
  assert.deepEqual(benchmarkCall(2, "doc", "/tmp/with space.json", "trust", "512M").args.slice(0, 2), ["-d", "memory_limit=512M"]);
});
