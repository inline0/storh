// @ts-nocheck
import { browser } from 'fumadocs-mdx/runtime/browser';
import type * as Config from '../source.config';

const create = browser<typeof Config, import("fumadocs-mdx/runtime/types").InternalTypeConfig & {
  DocData: {
  }
}>();
const browserCollections = {
  docs: create.doc("docs", {"api.mdx": () => import("../content/docs/api.mdx?collection=docs"), "atomicity.mdx": () => import("../content/docs/atomicity.mdx?collection=docs"), "benchmarks.mdx": () => import("../content/docs/benchmarks.mdx?collection=docs"), "bulk-data.mdx": () => import("../content/docs/bulk-data.mdx?collection=docs"), "caching.mdx": () => import("../content/docs/caching.mdx?collection=docs"), "engines.mdx": () => import("../content/docs/engines.mdx?collection=docs"), "index.mdx": () => import("../content/docs/index.mdx?collection=docs"), "indexes.mdx": () => import("../content/docs/indexes.mdx?collection=docs"), "install.mdx": () => import("../content/docs/install.mdx?collection=docs"), "jsonc.mdx": () => import("../content/docs/jsonc.mdx?collection=docs"), "maintenance-cli.mdx": () => import("../content/docs/maintenance-cli.mdx?collection=docs"), "queries.mdx": () => import("../content/docs/queries.mdx?collection=docs"), "query-builder.mdx": () => import("../content/docs/query-builder.mdx?collection=docs"), "quick-start.mdx": () => import("../content/docs/quick-start.mdx?collection=docs"), "scaling-limits.mdx": () => import("../content/docs/scaling-limits.mdx?collection=docs"), "schema.mdx": () => import("../content/docs/schema.mdx?collection=docs"), "sharding.mdx": () => import("../content/docs/sharding.mdx?collection=docs"), "sql-mirror.mdx": () => import("../content/docs/sql-mirror.mdx?collection=docs"), "uuidv7.mdx": () => import("../content/docs/uuidv7.mdx?collection=docs"), }),
};
export default browserCollections;