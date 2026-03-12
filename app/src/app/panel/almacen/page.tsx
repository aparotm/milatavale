import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { getMovementsForLocal, getUsers } from "@/lib/data";
import { requireSessionUser } from "@/lib/session";

export default async function AlmacenPanelPage() {
  const user = await requireSessionUser("almacen");
  const movements = await getMovementsForLocal(user.localCode ?? "");
  const users = await getUsers();
  const clients = users.filter(
    (demoUser) =>
      demoUser.role === "cliente" && demoUser.localCode === user.localCode,
  );

  const generated = movements
    .filter((movement) => movement.amount > 0)
    .reduce((sum, movement) => sum + movement.amount, 0);
  const spent = movements
    .filter((movement) => movement.amount < 0)
    .reduce((sum, movement) => sum + Math.abs(movement.amount), 0);
  const cans = movements.reduce((sum, movement) => sum + movement.canCount, 0);

  return (
    <AppShell
      title="Panel Almacén"
      subtitle="Base para clientes del local, KPIs y registro operativo."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Clientes del local" value={String(clients.length)} />
        <KpiCard label="Generado" value={`$${generated}`} />
        <KpiCard label="Canjeado" value={`$${spent}`} />
        <KpiCard label="Latas" value={String(cans)} />
      </div>

      <div className="panelGrid">
        <PanelCard title="Clientes asociados">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th>RUT</th>
                  <th>Email</th>
                </tr>
              </thead>
              <tbody>
                {clients.map((client) => (
                  <tr key={client.id}>
                    <td>{client.fullName}</td>
                    <td>{client.rut}</td>
                    <td>{client.email}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </PanelCard>

        <PanelCard title="Historial del local">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Cliente</th>
                  <th>Tipo</th>
                  <th>Monto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((movement) => (
                  <tr key={movement.id}>
                    <td>{movement.createdAt}</td>
                    <td>{movement.clientName}</td>
                    <td>{movement.type}</td>
                    <td>{movement.amount}</td>
                    <td>{movement.status}</td>
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
