export const dynamic = 'force-static';

export async function GET() {
  return Response.json({ status: 'success', backend: 'PHP REST API' });
}
