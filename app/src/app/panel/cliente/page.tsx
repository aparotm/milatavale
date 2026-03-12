import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { TableSearch } from "@/components/table-search";
import { getClientBalance, getMovementsForClient } from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
} from "@/lib/format";
import { requireSessionContext } from "@/lib/session";

export default async function ClientePanelPage() {
  const { user, impersonator } = await requireSessionContext("cliente");
  const movements = await getMovementsForClient(user.id);
  const balance = await getClientBalance(user.id);

  const reciclaje = movements
    .filter((movement) => movement.amount > 0 && movement.type === "ingreso")
    .reduce((sum, movement) => sum + movement.amount, 0);
  const incentivos = movements
    .filter((movement) => movement.type === "incentivo")
    .reduce((sum, movement) => sum + Math.max(movement.amount, 0), 0);
  const gastos = movements
    .filter((movement) => movement.amount < 0)
    .reduce((sum, movement) => sum + Math.abs(movement.amount), 0);
  const latas = movements
    .filter((movement) => movement.type === "ingreso")
    .reduce((sum, movement) => sum + movement.canCount, 0);

  let runningBalance = balance;
  const rows = movements.map((movement) => {
    const currentBalance = runningBalance;
    runningBalance -= movement.amount;
    return {
      ...movement,
      runningBalance: currentBalance,
    };
  });

  return (
    <AppShell
      title={`Hola, ${user.fullName.split(" ")[0] ?? user.fullName}`}
      subtitle=""
      user={user}
      adminViewer={impersonator}
      variant="frontend"
    >
      <div className="kpiGrid">
        <KpiCard label="Saldo disponible" value={formatMoney(balance)} />
        <KpiCard label="Generado por reciclaje" value={formatMoney(reciclaje)} />
        <KpiCard label="Generado por incentivos" value={formatMoney(incentivos)} />
        <KpiCard label="Canjeado" value={formatMoney(gastos)} />
      </div>

      <div className="kpiGrid">
        <KpiCard label="Latas" value={String(latas)} />
        <KpiCard label="Movimientos" value={String(movements.length)} />
        <KpiCard label="Tu local" value={user.localName ?? "—"} />
        <KpiCard label="Tu RUT" value={user.rut} />
      </div>

      <PanelCard title="Buscar">
        <TableSearch tableId="cliente-movimientos" />
      </PanelCard>

      <PanelCard
        title="Movimientos"
        description=""
      >
        <div className="tableWrap">
          <table id="cliente-movimientos">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Nombre local</th>
                <th>Tu RUT</th>
                <th>Latas</th>
                <th>Valor por lata</th>
                <th>Evidencia</th>
                <th>Monto</th>
                <th>Saldo</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((movement) => (
                <tr
                  data-search={[
                    movement.createdAt,
                    movement.type,
                    movement.localName ?? user.localName ?? movement.localCode,
                    movement.note ?? "",
                  ]
                    .join(" ")
                    .toLowerCase()}
                  key={movement.id}
                >
                  <td>{formatCompactDate(movement.createdAt)}</td>
                  <td>{movementLabel(movement.type)}</td>
                  <td>{movement.localName ?? user.localName ?? movement.localCode}</td>
                  <td>{user.rut}</td>
                  <td>{movement.type === "gasto" ? "—" : movement.canCount || "—"}</td>
                  <td>{movement.type === "gasto" ? "—" : "$10"}</td>
                  <td>
                    {movement.evidenceUrl ? (
                      <a href={movement.evidenceUrl} target="_blank">
                        Ver
                      </a>
                    ) : (
                      "—"
                    )}
                  </td>
                  <td>{formatMoney(movement.amount)}</td>
                  <td>{formatMoney(rows.length ? movement.runningBalance : 0)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </PanelCard>
    </AppShell>
  );
}
