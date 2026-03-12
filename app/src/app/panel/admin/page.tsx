import Link from "next/link";

import {
  createIncentiveAction,
  reverseMovementAction,
} from "@/app/actions";
import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import {
  getAuditEntries,
  getDiagnosticsSummary,
  getMovements,
  getUsers,
} from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
  statusLabel,
} from "@/lib/format";
import { requireSessionUser } from "@/lib/session";

export default async function AdminPanelPage({
  searchParams,
}: {
  searchParams?: Promise<Record<string, string | string[] | undefined>>;
}) {
  const user = await requireSessionUser("admin");
  const params = searchParams ? await searchParams : undefined;
  const tab = typeof params?.tab === "string" ? params.tab : "dashboard";
  const success = typeof params?.success === "string" ? params.success : "";
  const error = typeof params?.error === "string" ? params.error : "";
  const users = await getUsers();
  const movements = await getMovements();
  const auditEntries = await getAuditEntries(12);
  const diagnostics = await getDiagnosticsSummary();
  const clients = users.filter((item) => item.role === "cliente");
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
      actions={
        <div className="toolbar">
          <Link className="primaryButton" href="/panel/admin?tab=dashboard">
            Dashboard
          </Link>
          <Link className="primaryButton" href="/panel/admin?tab=incentivos">
            Incentivos
          </Link>
          <Link className="primaryButton" href="/panel/admin?tab=diagnostico">
            Diagnóstico
          </Link>
        </div>
      }
    >
      {success ? <div className="successBox">{success}</div> : null}
      {error ? <div className="errorBox">{error}</div> : null}

      {tab === "dashboard" ? (
        <>
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
                      <th>Acción</th>
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
                        <td>
                          {movement.type === "gasto" ? "—" : movement.canCount || "—"}
                        </td>
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
                        <td>
                          <form action={reverseMovementAction} className="formStack">
                            <input name="movementId" type="hidden" value={movement.id} />
                            <input
                              name="actorProfileId"
                              type="hidden"
                              value={user.id}
                            />
                            <input
                              name="note"
                              type="hidden"
                              value={`Reversa de ${movementLabel(movement.type)}`}
                            />
                            <button className="ghostButton" type="submit">
                              Reversar
                            </button>
                          </form>
                        </td>
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
        </>
      ) : null}

      {tab === "incentivos" ? (
        <div className="panelGrid">
          <PanelCard
            title="Registrar incentivo"
            description="Replica base del módulo de incentivos del admin del plugin."
          >
            <form action={createIncentiveAction} className="formStack">
              <input
                name="createdByProfileId"
                type="hidden"
                value={user.id}
              />
              <div className="field">
                <label htmlFor="incentive-client">Cliente</label>
                <select id="incentive-client" name="clientProfileId" required>
                  <option value="">Selecciona un cliente</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName} · {client.rut}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="incentive-local">Código local</label>
                <input
                  defaultValue={clients[0]?.localCode ?? ""}
                  id="incentive-local"
                  name="localCode"
                  required
                  type="text"
                />
              </div>
              <div className="field">
                <label htmlFor="incentive-amount">Monto</label>
                <input id="incentive-amount" min="1" name="amount" required type="number" />
              </div>
              <div className="field">
                <label htmlFor="incentive-note">Detalle</label>
                <textarea id="incentive-note" name="note" rows={3} />
              </div>
              <button className="primaryButton" type="submit">
                Registrar incentivo
              </button>
            </form>
          </PanelCard>
        </div>
      ) : null}

      {tab === "diagnostico" ? (
        <div className="panelGrid">
          <div className="kpiGrid">
            <KpiCard
              label="RUT duplicados"
              value={String(diagnostics.duplicateRutCount)}
            />
            <KpiCard
              label="Usuarios sin local"
              value={String(diagnostics.usersWithoutLocal)}
            />
            <KpiCard
              label="Pendientes retiro"
              value={String(diagnostics.pendingMovements)}
            />
            <KpiCard
              label="Usuarios inactivos"
              value={String(diagnostics.inactiveUsers)}
            />
          </div>

          <PanelCard title="Diagnóstico">
            <ul className="heroList">
              <li>Conteo de RUT duplicados para futura consolidación</li>
              <li>Usuarios sin local asignado</li>
              <li>Movimientos aún pendientes de retiro</li>
              <li>Base lista para sumar reglas más estrictas</li>
            </ul>
          </PanelCard>
        </div>
      ) : null}
    </AppShell>
  );
}
