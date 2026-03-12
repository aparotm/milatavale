import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { getAuditEntries, getMovements, getUsers } from "@/lib/data";
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
  const auditEntries = await getAuditEntries(12);
  const totalGenerated = movements
    .filter((movement) => movement.amount > 0)
    .reduce((sum, movement) => sum + movement.amount, 0);
  const totalSpent = movements
    .filter((movement) => movement.amount < 0)
    .reduce((sum, movement) => sum + Math.abs(movement.amount), 0);
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
      subtitle="Backoffice inicial con lectura de usuarios, ledger y auditoría."
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

      <div className="kpiGrid">
        <KpiCard label="Reciclaje total" value={formatMoney(totalGenerated)} />
        <KpiCard label="Canjes total" value={formatMoney(totalSpent)} />
        <KpiCard
          label="Ingresos"
          value={String(movements.filter((item) => item.type === "ingreso").length)}
        />
        <KpiCard
          label="Gastos"
          value={String(movements.filter((item) => item.type === "gasto").length)}
        />
      </div>

      <div className="panelGrid">
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
                  <th>RUT</th>
                  <th>Local</th>
                  <th>Latas</th>
                  <th>Evidencia</th>
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
                    <td>{movement.clientRut}</td>
                    <td>{movement.localName ?? movement.localCode}</td>
                    <td>{movement.type === "gasto" ? "—" : movement.canCount || "—"}</td>
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
                    <td>{statusLabel(movement.status)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </PanelCard>

        <PanelCard title="Auditoría reciente">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Actor</th>
                  <th>Acción</th>
                  <th>Objeto</th>
                  <th>ID</th>
                </tr>
              </thead>
              <tbody>
                {auditEntries.map((entry) => (
                  <tr key={entry.id}>
                    <td>{formatCompactDate(entry.createdAt)}</td>
                    <td>{entry.actorName}</td>
                    <td>{entry.action}</td>
                    <td>{entry.objectType}</td>
                    <td>{entry.objectId}</td>
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
