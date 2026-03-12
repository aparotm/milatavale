import { AppShell } from "@/components/shell";
import { DetailTable, KpiCard, PanelCard } from "@/components/cards";
import { getMovementsForLocal, getUsers } from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
  statusLabel,
} from "@/lib/format";
import { requireSessionUser } from "@/lib/session";

export default async function AlmacenPanelPage() {
  const user = await requireSessionUser("almacen");
  const movements = await getMovementsForLocal(user.localCode ?? "");
  const users = await getUsers();
  const clients = users.filter(
    (appUser) =>
      appUser.role === "cliente" && appUser.localCode === user.localCode,
  );

  const generated = movements
    .filter((movement) => movement.amount > 0)
    .reduce((sum, movement) => sum + movement.amount, 0);
  const spent = movements
    .filter((movement) => movement.amount < 0)
    .reduce((sum, movement) => sum + Math.abs(movement.amount), 0);
  const cans = movements.reduce((sum, movement) => sum + movement.canCount, 0);
  const pending = movements.filter(
    (movement) => movement.status === "pendiente_retiro",
  );

  const clientRows = clients.map((client) => {
    const clientMovements = movements.filter(
      (movement) => movement.clientId === client.id,
    );

    return {
      ...client,
      generated: clientMovements
        .filter((movement) => movement.amount > 0)
        .reduce((sum, movement) => sum + movement.amount, 0),
      canjeado: clientMovements
        .filter((movement) => movement.amount < 0)
        .reduce((sum, movement) => sum + Math.abs(movement.amount), 0),
      balance: clientMovements.reduce((sum, movement) => sum + movement.amount, 0),
    };
  });

  return (
    <AppShell
      title="Panel Almacén"
      subtitle="Vista del local, clientes, KPIs y trazabilidad operativa."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Clientes del local" value={String(clients.length)} />
        <KpiCard label="Generado" value={formatMoney(generated)} />
        <KpiCard label="Canjeado" value={formatMoney(spent)} />
        <KpiCard label="Latas" value={String(cans)} />
      </div>

      <div className="kpiGrid">
        <KpiCard label="Pendientes retiro" value={String(pending.length)} />
        <KpiCard
          label="Monto pendiente"
          value={formatMoney(
            pending.reduce((sum, movement) => sum + Math.max(movement.amount, 0), 0),
          )}
        />
        <KpiCard label="Local" value={user.localName ?? "—"} note={user.localCode} />
        <KpiCard label="Representante" value={user.fullName} note={user.rut} />
      </div>

      <div className="panelGrid">
        <PanelCard
          title="Información del local"
          description="Base para la ficha operativa del almacén."
        >
          <DetailTable
            rows={[
              { label: "Nombre del local", value: user.localName ?? "—" },
              { label: "Código", value: user.localCode ?? "—" },
              { label: "Representante", value: user.fullName },
              { label: "Email", value: user.email },
            ]}
          />
        </PanelCard>

        <PanelCard
          title="Acciones próximas"
          description="Bloques siguientes para completar paridad funcional."
        >
          <div className="actionRow">
            <div className="actionCard">
              <strong>Registrar latas</strong>
              <p className="muted">Ingreso con confirmación y evidencia.</p>
            </div>
            <div className="actionCard">
              <strong>Registrar gasto</strong>
              <p className="muted">Descuento de saldo por cliente.</p>
            </div>
            <div className="actionCard">
              <strong>Registrar cliente</strong>
              <p className="muted">Alta y asociación directa al local.</p>
            </div>
          </div>
        </PanelCard>

        <PanelCard
          title="Clientes asociados"
          description="Saldo y actividad resumida por cliente."
        >
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th>RUT</th>
                  <th>Email</th>
                  <th>Generado</th>
                  <th>Canjeado</th>
                  <th>Saldo</th>
                </tr>
              </thead>
              <tbody>
                {clientRows.map((client) => (
                  <tr key={client.id}>
                    <td>{client.fullName}</td>
                    <td>{client.rut}</td>
                    <td>{client.email}</td>
                    <td>{formatMoney(client.generated)}</td>
                    <td>{formatMoney(client.canjeado)}</td>
                    <td>{formatMoney(client.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </PanelCard>

        <PanelCard
          title="Historial del local"
          description="Movimientos generados en este almacén."
        >
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Cliente</th>
                  <th>Tipo</th>
                  <th>Latas</th>
                  <th>Monto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((movement) => (
                  <tr key={movement.id}>
                    <td>{formatCompactDate(movement.createdAt)}</td>
                    <td>{movement.clientName}</td>
                    <td>{movementLabel(movement.type)}</td>
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
