import { AppShell } from "@/components/shell";
import { DetailTable, KpiCard, PanelCard } from "@/components/cards";
import { getMovementsForLocal } from "@/lib/data";
import { formatCompactDate, formatMoney } from "@/lib/format";
import { requireSessionUser } from "@/lib/session";

export default async function GestorPanelPage() {
  const user = await requireSessionUser("gestor");
  const movements = await getMovementsForLocal(user.localCode ?? "");
  const pending = movements.filter(
    (movement) => movement.status === "pendiente_retiro",
  );

  return (
    <AppShell
      title="Panel Gestor"
      subtitle="Vista logística de retiros pendientes por local."
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
          value={formatMoney(
            pending.reduce((sum, movement) => sum + Math.max(movement.amount, 0), 0),
          )}
        />
        <KpiCard label="Local" value={user.localName ?? "—"} />
      </div>

      <div className="panelGrid">
        <PanelCard title="Información del gestor">
          <DetailTable
            rows={[
              { label: "Nombre", value: user.fullName },
              { label: "Email", value: user.email },
              { label: "RUT", value: user.rut },
              { label: "Local", value: user.localName ?? "—" },
            ]}
          />
        </PanelCard>

        <PanelCard
          title="Retiros disponibles"
          description="Movimientos pendientes de retiro asociados al local."
        >
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
                    <td>{formatCompactDate(movement.createdAt)}</td>
                    <td>{movement.clientName}</td>
                    <td>{movement.clientRut}</td>
                    <td>{movement.canCount}</td>
                    <td>{formatMoney(movement.amount)}</td>
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
