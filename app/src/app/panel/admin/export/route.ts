import { cookies } from "next/headers";

import { getMovements, getUserById } from "@/lib/data";
import { SESSION_COOKIE } from "@/lib/session";

export async function GET() {
  const cookieStore = await cookies();
  const userId = cookieStore.get(SESSION_COOKIE)?.value;

  if (!userId) {
    return new Response("Unauthorized", { status: 401 });
  }

  const user = await getUserById(userId);
  if (!user || user.role !== "admin") {
    return new Response("Forbidden", { status: 403 });
  }

  const movements = await getMovements();
  const header = [
    "Fecha",
    "Tipo",
    "Cliente",
    "RUT",
    "Local",
    "Latas",
    "Monto",
    "Estado",
    "Evidencia",
    "Detalle",
  ];

  const rows = movements.map((movement) => [
    movement.createdAt,
    movement.type,
    movement.clientName,
    movement.clientRut,
    movement.localName ?? movement.localCode,
    String(movement.canCount),
    String(movement.amount),
    movement.status,
    movement.evidenceUrl ?? "",
    movement.note ?? "",
  ]);

  const csv = [header, ...rows]
    .map((row) =>
      row
        .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
        .join(","),
    )
    .join("\n");

  return new Response(csv, {
    status: 200,
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="mlv_movimientos_${new Date()
        .toISOString()
        .slice(0, 19)
        .replaceAll(":", "-")}.csv"`,
    },
  });
}
