// @ts-nocheck
import { browser } from 'fumadocs-mdx/runtime/browser';
import type * as Config from '../source.config';

const create = browser<typeof Config, import("fumadocs-mdx/runtime/types").InternalTypeConfig & {
  DocData: {
  }
}>();
const browserCollections = {
  docs: create.doc("docs", {"api.mdx": () => import("../content/docs/api.mdx?collection=docs"), "atomicity.mdx": () => import("../content/docs/atomicity.mdx?collection=docs"), "engines.mdx": () => import("../content/docs/engines.mdx?collection=docs"), "index.mdx": () => import("../content/docs/index.mdx?collection=docs"), "install.mdx": () => import("../content/docs/install.mdx?collection=docs"), "jsonc.mdx": () => import("../content/docs/jsonc.mdx?collection=docs"), "queries.mdx": () => import("../content/docs/queries.mdx?collection=docs"), "quick-start.mdx": () => import("../content/docs/quick-start.mdx?collection=docs"), "scaling-limits.mdx": () => import("../content/docs/scaling-limits.mdx?collection=docs"), "sharding.mdx": () => import("../content/docs/sharding.mdx?collection=docs"), "uuidv7.mdx": () => import("../content/docs/uuidv7.mdx?collection=docs"), }),
};
export default browserCollections;