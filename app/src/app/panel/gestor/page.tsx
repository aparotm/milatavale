import { setRetiradoAction } from "@/app/actions";
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
      subtitle="Retiros disponibles por local y actualización del estado logístico."
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
          description="Replica del bloque de retiros pendientes del plugin."
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
                  <th>Acción</th>
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
                    <td>
                      <form action={setRetiradoAction}>
                        <input name="movementId" type="hidden" value={movement.id} />
                        <input name="actorProfileId" type="hidden" value={user.id} />
                        <input name="retirado" type="hidden" value="1" />
                        <input name="backTo" type="hidden" value="gestor" />
                        <button className="secondaryButton" type="submit">
                          Marcar retirado
                        </button>
                      </form>
                    </td>
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
