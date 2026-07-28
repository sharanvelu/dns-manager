import { buildSearchIndex } from "@/lib/search";

/**
 * Static-export route handler → emitted as `out/search-index.json` at
 * build time and fetched lazily by components/SearchDialog.tsx.
 */
export const dynamic = "force-static";

export async function GET() {
  const index = await buildSearchIndex();
  return Response.json(index);
}
