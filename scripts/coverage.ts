#!/usr/bin/env node
import * as Cli from "effect/cli/Command";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { main } from "./lib/cli.ts";
import { run } from "./lib/command.ts";
export const command = Cli.make("coverage", {}, () => Effect.gen(function* () {
  const fs = yield* FileSystem.FileSystem;
  yield* fs.makeDirectory("build", { recursive: true });
  yield* run({ command: process.env.PHP_BINARY || "php", args: ["vendor/bin/phpunit", "--coverage-clover", "build/clover.xml", "--colors=never"], env: { XDEBUG_MODE: "coverage" }, inherit: true });
}));
if (import.meta.main) main(command);
