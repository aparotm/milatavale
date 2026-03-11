import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { getMovementsForLocal } from "@/lib/demo-data";
import { requireSessionUser } from "@/lib/session";

export default async function GestorPanelPage() {
  const user = await requireSessionUser("gestor");
  const movements = getMovementsForLocal(user.localCode ?? "");
  const pending = movements.filter(
    (movement) => movement.status === "pendiente_retiro",
  );

  return (
    <AppShell
      title="Panel Gestor"
      subtitle="Vista logística para retiros pendientes por local."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Retiros pendientes" value={String(pending.length)} />
        <KpiCard
          label="Latas pendientes"
          value={String(pending.reduce((sum, movement) => sum + movement.canCount, 0))}
        />
        <KpiCard
          label="Monto asociado"
          value={`$${pending.reduce((sum, movement) => sum + Math.max(movement.amount, 0), 0)}`}
        />
        <KpiCard label="Local" value={user.localName ?? "—"} />
      </div>

      <div className="panelGrid">
        <PanelCard title="Retiros disponibles">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Cliente</th>
                  <th>RUT</th>
                  <th>Latas</th>
                  <th>Monto</th>
                </tr>
              </thead>
              <tbody>
                {pending.map((movement) => (
                  <tr key={movement.id}>
                    <td>{movement.createdAt}</td>
                    <td>{movement.clientName}</td>
                    <td>{movement.clientRut}</td>
                    <td>{movement.canCount}</td>
                    <td>{movement.amount}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </PanelCard>
      </div>
    </AppShell>
  );
}
