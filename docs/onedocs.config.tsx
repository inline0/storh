import { defineConfig } from "onedocs/config";
import {
  Braces,
  Clock3,
  Database,
  FileJson,
  GitCommitHorizontal,
  HardDrive,
  ListChecks,
  Rows3,
} from "lucide-react";
import { HeroLeft } from "./src/components/hero-left";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "storh",
  description:
    "File-first records for PHP: JSONC documents, append-only segmented logs, and atomic directory queues with UUIDv7 ids.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: { light: "/icon.svg", dark: "/icon-dark.svg" },
  nav: {
    github: "inline0/storh",
  },
  footer: {
    links: [{ label: "Inline0.com", href: "https://inline0.com" }],
  },
  homepage: {
    hero: {
      left: HeroLeft,
    },
    features: [
      {
        title: "JSONC Document Store",
        description:
          "One JSONC file per record, UUIDv7 ids, prefix sharding, atomic replacement, and streaming field filters.",
        icon: <FileJson className={iconClass} />,
      },
      {
        title: "Segmented Log",
        description:
          "Append-only NDJSON segments, sparse indexes, cursor and time-range reads, crash recovery, and compaction.",
        icon: <Rows3 className={iconClass} />,
      },
      {
        title: "Directory Queue",
        description:
          "Jobs move atomically between pending, processing, and done lanes using filesystem renames.",
        icon: <ListChecks className={iconClass} />,
      },
      {
        title: "UUIDv7 Records",
        description:
          "Sortable, time-addressable ids for stable cursors, bounded scans, and prefix-sharded storage paths.",
        icon: <Clock3 className={iconClass} />,
      },
      {
        title: "Atomic Writes",
        description:
          "Temporary-file writes and atomic renames keep records whole, with startup cleanup for abandoned temp files.",
        icon: <HardDrive className={iconClass} />,
      },
      {
        title: "Torn-Write Recovery",
        description:
          "Segment reads validate length and checksum fields; recovery truncates partial trailing lines.",
        icon: <GitCommitHorizontal className={iconClass} />,
      },
      {
        title: "File-First",
        description:
          "No database server, no framework adapter, no Composer runtime packages; callers pass a directory.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "JSONC Native",
        description:
          "Storage files accept comments and trailing commas while writing stable pretty JSONC objects.",
        icon: <Braces className={iconClass} />,
      },
    ],
  },
});
