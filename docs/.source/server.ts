// @ts-nocheck
import * as __fd_glob_11 from "../content/docs/uuidv7.mdx?collection=docs"
import * as __fd_glob_10 from "../content/docs/sharding.mdx?collection=docs"
import * as __fd_glob_9 from "../content/docs/scaling-limits.mdx?collection=docs"
import * as __fd_glob_8 from "../content/docs/quick-start.mdx?collection=docs"
import * as __fd_glob_7 from "../content/docs/queries.mdx?collection=docs"
import * as __fd_glob_6 from "../content/docs/jsonc.mdx?collection=docs"
import * as __fd_glob_5 from "../content/docs/install.mdx?collection=docs"
import * as __fd_glob_4 from "../content/docs/index.mdx?collection=docs"
import * as __fd_glob_3 from "../content/docs/engines.mdx?collection=docs"
import * as __fd_glob_2 from "../content/docs/atomicity.mdx?collection=docs"
import * as __fd_glob_1 from "../content/docs/api.mdx?collection=docs"
import { default as __fd_glob_0 } from "../content/docs/meta.json?collection=docs"
import { server } from 'fumadocs-mdx/runtime/server';
import type * as Config from '../source.config';

const create = server<typeof Config, import("fumadocs-mdx/runtime/types").InternalTypeConfig & {
  DocData: {
  }
}>({"doc":{"passthroughs":["extractedReferences"]}});

export const docs = await create.docs("docs", "content/docs", {"meta.json": __fd_glob_0, }, {"api.mdx": __fd_glob_1, "atomicity.mdx": __fd_glob_2, "engines.mdx": __fd_glob_3, "index.mdx": __fd_glob_4, "install.mdx": __fd_glob_5, "jsonc.mdx": __fd_glob_6, "queries.mdx": __fd_glob_7, "quick-start.mdx": __fd_glob_8, "scaling-limits.mdx": __fd_glob_9, "sharding.mdx": __fd_glob_10, "uuidv7.mdx": __fd_glob_11, });