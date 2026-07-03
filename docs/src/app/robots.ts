import { generateRobots } from "onedocs/seo";

const baseUrl = "https://storh.dev";

export default function robots() {
  return generateRobots({ baseUrl });
}
