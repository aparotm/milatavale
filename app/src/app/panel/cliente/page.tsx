import { AppShell } from "@/components/shell";
import { DetailTable, KpiCard, PanelCard } from "@/components/cards";
import { getClientBalance, getMovementsForClient } from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
  statusLabel,
} from "@/lib/format";
import { requireSessionUser } from "@/lib/session";

export default async function ClientePanelPage() {
  const user = await requireSessionUser("cliente");
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
    .filter((movement) => movement.amount >= 0)
    .reduce((sum, movement) => sum + movement.canCount, 0);
  const pendientes = movements.filter(
    (movement) => movement.status === "pendiente_retiro",
  ).length;

  return (
    <AppShell
      title="Panel Cliente"
      subtitle="Saldo, trazabilidad de movimientos y referencia del local asignado."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Saldo disponible" value={formatMoney(balance)} />
        <KpiCard label="Latas" value={String(latas)} />
        <KpiCard label="Monto reciclaje" value={formatMoney(reciclaje)} />
        <KpiCard label="Gastos" value={formatMoney(gastos)} />
      </div>

      <div className="kpiGrid">
        <KpiCard label="Incentivos" value={formatMoney(incentivos)} />
        <KpiCard label="Movimientos" value={String(movements.length)} />
        <KpiCard
          label="Pendientes retiro"
          value={String(pendientes)}
          note="Estado logístico"
        />
        <KpiCard
          label="Local"
          value={user.localName ?? "Sin asignación"}
          note={user.localCode ?? ""}
        />
      </div>

      <div className="panelGrid">
        <PanelCard
          title="Información personal"
          description="Datos del cliente usados en la operación."
        >
          <DetailTable
            rows={[
              { label: "Nombre", value: user.fullName },
              { label: "Email", value: user.email },
              { label: "RUT", value: user.rut },
            ]}
          />
        </PanelCard>

        <PanelCard
          title="Almacén asignado"
          description="Bloque equivalente al resumen de local del plugin."
        >
          <DetailTable
            rows={[
              { label: "Nombre local", value: user.localName ?? "—" },
              { label: "Código local", value: user.localCode ?? "—" },
              { label: "Estado", value: "Activo" },
            ]}
          />
        </PanelCard>

        <PanelCard
          title="Lectura funcional"
          description="Paridad conceptual con el sistema WordPress."
        >
          <ul className="heroList">
            <li>Los ingresos aumentan saldo de inmediato</li>
            <li>Los gastos descuentan saldo de inmediato</li>
            <li>El retiro es logístico y no altera el saldo</li>
            <li>El historial se renderiza desde el ledger</li>
          </ul>
        </PanelCard>

        <PanelCard
          title="Movimientos"
          description="Historial contable y operativo del cliente."
        >
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tipo</th>
                  <th>Nombre local</th>
                  <th>Latas</th>
                  <th>Monto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((movement) => (
                  <tr key={movement.id}>
                    <td>{formatCompactDate(movement.createdAt)}</td>
                    <td>{movementLabel(movement.type)}</td>
                    <td>{user.localName ?? movement.localCode}</td>
                    <td>{movement.canCount || "—"}</td>
                    <td>{formatMoney(movement.amount)}</td>
                    <td>{statusLabel(movement.status)}</td>
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
