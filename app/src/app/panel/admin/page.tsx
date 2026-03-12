import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { getMovements, getUsers } from "@/lib/data";
import { requireSessionUser } from "@/lib/session";

export default async function AdminPanelPage() {
  const user = await requireSessionUser("admin");
  const users = await getUsers();
  const movements = await getMovements();

  const totalBalance = movements.reduce((sum, movement) => sum + movement.amount, 0);
  const pendingPickups = movements.filter(
    (movement) => movement.status === "pendiente_retiro",
  ).length;

  return (
    <AppShell
      title="Panel Admin"
      subtitle="Primer panel de control para validar la réplica del backoffice."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Usuarios" value={String(users.length)} />
        <KpiCard label="Movimientos" value={String(movements.length)} />
        <KpiCard label="Saldo neto demo" value={`$${totalBalance}`} />
        <KpiCard label="Pendientes retiro" value={String(pendingPickups)} />
      </div>

      <div className="panelGrid">
        <PanelCard
          title="Qué replica este panel"
          description="Todavía no es el admin final, pero ya marca la dirección."
        >
          <ul className="heroList">
            <li>Vista global por rol y movimiento</li>
            <li>Base para auditoría, incentivos y ajustes</li>
            <li>Futuro reemplazo del admin de WordPress</li>
          </ul>
        </PanelCard>

        <PanelCard title="Movimientos recientes">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Tipo</th>
                  <th>Cliente</th>
                  <th>Local</th>
                  <th>Monto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((movement) => (
                  <tr key={movement.id}>
                    <td>{movement.id}</td>
                    <td>
                      <span className={`tag ${movement.status === "pendiente_retiro" ? "warn" : ""}`}>
                        {movement.type}
                      </span>
                    </td>
                    <td>{movement.clientName}</td>
                    <td>{movement.localCode}</td>
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
