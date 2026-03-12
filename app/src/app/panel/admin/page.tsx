import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { getMovements, getUsers } from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
  statusLabel,
} from "@/lib/format";
import { requireSessionUser } from "@/lib/session";

export default async function AdminPanelPage() {
  const user = await requireSessionUser("admin");
  const users = await getUsers();
  const movements = await getMovements();

  const totalBalance = movements.reduce((sum, movement) => sum + movement.amount, 0);
  const pendingPickups = movements.filter(
    (movement) => movement.status === "pendiente_retiro",
  ).length;
  const usersByRole = {
    admin: users.filter((item) => item.role === "admin").length,
    almacen: users.filter((item) => item.role === "almacen").length,
    cliente: users.filter((item) => item.role === "cliente").length,
    gestor: users.filter((item) => item.role === "gestor").length,
  };

  return (
    <AppShell
      title="Panel Admin"
      subtitle="Base del backoffice nuevo con lectura de usuarios y ledger real."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Usuarios" value={String(users.length)} />
        <KpiCard label="Movimientos" value={String(movements.length)} />
        <KpiCard label="Saldo neto" value={formatMoney(totalBalance)} />
        <KpiCard label="Pendientes retiro" value={String(pendingPickups)} />
      </div>

      <div className="kpiGrid">
        <KpiCard label="Admins" value={String(usersByRole.admin)} />
        <KpiCard label="Almacenes" value={String(usersByRole.almacen)} />
        <KpiCard label="Clientes" value={String(usersByRole.cliente)} />
        <KpiCard label="Gestores" value={String(usersByRole.gestor)} />
      </div>

      <div className="panelGrid">
        <PanelCard
          title="Módulos administrativos"
          description="Siguiente objetivo: emular el admin de WordPress con mejor estructura."
        >
          <ul className="heroList">
            <li>Movimientos globales con KPIs y filtros</li>
            <li>Usuarios por rol y local</li>
            <li>Base para incentivos, reversas y ajustes</li>
            <li>Diagnóstico, auditoría y exportables en la siguiente iteración</li>
          </ul>
        </PanelCard>

        <PanelCard title="Usuarios por rol">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Email</th>
                  <th>Rol</th>
                  <th>RUT</th>
                  <th>Local</th>
                </tr>
              </thead>
              <tbody>
                {users.map((appUser) => (
                  <tr key={appUser.id}>
                    <td>{appUser.fullName}</td>
                    <td>{appUser.email}</td>
                    <td>{appUser.role}</td>
                    <td>{appUser.rut}</td>
                    <td>{appUser.localName ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </PanelCard>

        <PanelCard title="Movimientos recientes">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
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
                    <td>{formatCompactDate(movement.createdAt)}</td>
                    <td>
                      <span
                        className={`tag ${
                          movement.status === "pendiente_retiro" ? "warn" : ""
                        }`}
                      >
                        {movementLabel(movement.type)}
                      </span>
                    </td>
                    <td>{movement.clientName}</td>
                    <td>{movement.localCode}</td>
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
