// @ts-nocheck
import * as __fd_glob_19 from "../content/docs/uuidv7.mdx?collection=docs"
import * as __fd_glob_18 from "../content/docs/sql-mirror.mdx?collection=docs"
import * as __fd_glob_17 from "../content/docs/sharding.mdx?collection=docs"
import * as __fd_glob_16 from "../content/docs/schema.mdx?collection=docs"
import * as __fd_glob_15 from "../content/docs/scaling-limits.mdx?collection=docs"
import * as __fd_glob_14 from "../content/docs/quick-start.mdx?collection=docs"
import * as __fd_glob_13 from "../content/docs/query-builder.mdx?collection=docs"
import * as __fd_glob_12 from "../content/docs/queries.mdx?collection=docs"
import * as __fd_glob_11 from "../content/docs/maintenance-cli.mdx?collection=docs"
import * as __fd_glob_10 from "../content/docs/jsonc.mdx?collection=docs"
import * as __fd_glob_9 from "../content/docs/install.mdx?collection=docs"
import * as __fd_glob_8 from "../content/docs/indexes.mdx?collection=docs"
import * as __fd_glob_7 from "../content/docs/index.mdx?collection=docs"
import * as __fd_glob_6 from "../content/docs/engines.mdx?collection=docs"
import * as __fd_glob_5 from "../content/docs/caching.mdx?collection=docs"
import * as __fd_glob_4 from "../content/docs/bulk-data.mdx?collection=docs"
import * as __fd_glob_3 from "../content/docs/benchmarks.mdx?collection=docs"
import * as __fd_glob_2 from "../content/docs/atomicity.mdx?collection=docs"
import * as __fd_glob_1 from "../content/docs/api.mdx?collection=docs"
import { default as __fd_glob_0 } from "../content/docs/meta.json?collection=docs"
import { server } from 'fumadocs-mdx/runtime/server';
import type * as Config from '../source.config';

const create = server<typeof Config, import("fumadocs-mdx/runtime/types").InternalTypeConfig & {
  DocData: {
  }
}>({"doc":{"passthroughs":["extractedReferences"]}});

export const docs = await create.docs("docs", "content/docs", {"meta.json": __fd_glob_0, }, {"api.mdx": __fd_glob_1, "atomicity.mdx": __fd_glob_2, "benchmarks.mdx": __fd_glob_3, "bulk-data.mdx": __fd_glob_4, "caching.mdx": __fd_glob_5, "engines.mdx": __fd_glob_6, "index.mdx": __fd_glob_7, "indexes.mdx": __fd_glob_8, "install.mdx": __fd_glob_9, "jsonc.mdx": __fd_glob_10, "maintenance-cli.mdx": __fd_glob_11, "queries.mdx": __fd_glob_12, "query-builder.mdx": __fd_glob_13, "quick-start.mdx": __fd_glob_14, "scaling-limits.mdx": __fd_glob_15, "schema.mdx": __fd_glob_16, "sharding.mdx": __fd_glob_17, "sql-mirror.mdx": __fd_glob_18, "uuidv7.mdx": __fd_glob_19, });