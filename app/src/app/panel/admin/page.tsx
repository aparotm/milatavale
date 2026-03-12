import Link from "next/link";

import {
  createAdjustmentAction,
  createIncentiveAction,
  impersonateUserAction,
  createRegularizationAction,
  importCsvAction,
  mergeClientsAction,
  reverseMovementAction,
} from "@/app/actions";
import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import {
  getAuditEntries,
  getDiagnosticsSummary,
  getDuplicateRutGroups,
  getMovements,
  getUsers,
} from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
  statusLabel,
} from "@/lib/format";
import { requireSessionContext } from "@/lib/session";

export default async function AdminPanelPage({
  searchParams,
}: {
  searchParams?: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { user, impersonator } = await requireSessionContext("admin");
  const params = searchParams ? await searchParams : undefined;
  const tab = typeof params?.tab === "string" ? params.tab : "dashboard";
  const status = typeof params?.status === "string" ? params.status : "all";
  const movementType = typeof params?.movementType === "string" ? params.movementType : "all";
  const clientFilter = typeof params?.client === "string" ? params.client : "";
  const localFilter = typeof params?.local === "string" ? params.local : "";
  const query = typeof params?.q === "string" ? params.q.toLowerCase() : "";
  const success = typeof params?.success === "string" ? params.success : "";
  const error = typeof params?.error === "string" ? params.error : "";
  const users = await getUsers();
  const movements = await getMovements();
  const auditEntries = await getAuditEntries(12);
  const diagnostics = await getDiagnosticsSummary();
  const duplicateGroups = await getDuplicateRutGroups();
  const clients = users.filter((item) => item.role === "cliente");
  const locals = Array.from(
    new Set(users.map((item) => item.localCode).filter(Boolean)),
  ) as string[];
  const filteredMovements = movements.filter((movement) => {
    if (status !== "all" && movement.status !== status) return false;
    if (movementType !== "all" && movement.type !== movementType) return false;
    if (clientFilter && movement.clientId !== clientFilter) return false;
    if (localFilter && movement.localCode !== localFilter) return false;
    if (
      query &&
      ![
        movement.clientName,
        movement.clientRut,
        movement.localName ?? movement.localCode,
        movement.note ?? "",
      ]
        .join(" ")
        .toLowerCase()
        .includes(query)
    ) {
      return false;
    }
    return true;
  });
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
      adminViewer={impersonator}
      variant="admin"
    >
      {success ? <div className="successBox">{success}</div> : null}
      {error ? <div className="errorBox">{error}</div> : null}

      <div className="adminLayout">
        <aside className="adminSidebar">
          <h3>Administración</h3>
          <nav className="adminMenu">
            <Link className={tab === "dashboard" ? "isActive" : ""} href="/panel/admin?tab=dashboard">Movimientos</Link>
            <Link className={tab === "incentivos" ? "isActive" : ""} href="/panel/admin?tab=incentivos">Incentivos</Link>
            <Link className={tab === "ajustes" ? "isActive" : ""} href="/panel/admin?tab=ajustes">Ajustes contables</Link>
            <Link className={tab === "regularizacion" ? "isActive" : ""} href="/panel/admin?tab=regularizacion">Regularización histórica</Link>
            <Link className={tab === "duplicados" ? "isActive" : ""} href="/panel/admin?tab=duplicados">RUT duplicados</Link>
            <Link className={tab === "diagnostico" ? "isActive" : ""} href="/panel/admin?tab=diagnostico">Diagnóstico</Link>
            <Link className={tab === "importar" ? "isActive" : ""} href="/panel/admin?tab=importar">Importar CSV</Link>
            <a href="/panel/admin/export">Exportar CSV</a>
          </nav>
        </aside>

        <div className="adminContent">

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
            <PanelCard title="Ver como usuario">
              <form action={impersonateUserAction} className="formStack">
                <div className="field">
                  <label htmlFor="admin-view-as">Usuario</label>
                  <select id="admin-view-as" name="targetUserId" required>
                    <option value="">Selecciona un usuario</option>
                    {users
                      .filter((appUser) => appUser.id !== user.id)
                      .map((appUser) => (
                        <option key={appUser.id} value={appUser.id}>
                          {appUser.fullName} · {appUser.role} · {appUser.rut}
                        </option>
                      ))}
                  </select>
                </div>
                <button className="secondaryButton" type="submit">
                  Ver como
                </button>
              </form>
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

            <PanelCard title="Movimientos">
              <form className="adminFilters" method="get">
                <input name="tab" type="hidden" value="dashboard" />
                <input
                  className="searchInput"
                  defaultValue={query}
                  name="q"
                  placeholder="Buscar por cliente, RUT, local o detalle"
                />
                <select defaultValue={status} name="status">
                  <option value="all">Todos los estados</option>
                  <option value="pendiente_retiro">Pendiente de retiro</option>
                  <option value="retirado">Retirado</option>
                </select>
                <select defaultValue={movementType} name="movementType">
                  <option value="all">Todos los tipos</option>
                  <option value="ingreso">Ingreso</option>
                  <option value="gasto">Gasto</option>
                  <option value="incentivo">Incentivo</option>
                  <option value="ajuste">Ajuste</option>
                </select>
                <select defaultValue={clientFilter} name="client">
                  <option value="">Todos los clientes</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName}
                    </option>
                  ))}
                </select>
                <select defaultValue={localFilter} name="local">
                  <option value="">Todos los locales</option>
                  {locals.map((local) => (
                    <option key={local} value={local}>
                      {local}
                    </option>
                  ))}
                </select>
                <button className="secondaryButton" type="submit">
                  Filtrar
                </button>
              </form>

              <div className="tableWrap">
                <table className="adminTable">
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
                    {filteredMovements.map((movement) => (
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

      {tab === "ajustes" ? (
        <div className="panelGrid">
          <PanelCard
            title="Ajuste contable"
            description="Abona o descuenta saldo de un cliente, siguiendo la lógica del plugin."
          >
            <form action={createAdjustmentAction} className="formStack">
              <input name="createdByProfileId" type="hidden" value={user.id} />
              <div className="field">
                <label htmlFor="adjustment-client">Cliente</label>
                <select id="adjustment-client" name="clientProfileId" required>
                  <option value="">Selecciona un cliente</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName} · {client.rut}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="adjustment-local">Código local</label>
                <input
                  defaultValue={clients[0]?.localCode ?? ""}
                  id="adjustment-local"
                  name="localCode"
                  required
                  type="text"
                />
              </div>
              <div className="field">
                <label htmlFor="adjustment-direction">Tipo</label>
                <select id="adjustment-direction" name="direction" required>
                  <option value="abonar">Abonar</option>
                  <option value="descontar">Descontar</option>
                </select>
              </div>
              <div className="field">
                <label htmlFor="adjustment-amount">Monto</label>
                <input id="adjustment-amount" min="1" name="amount" required type="number" />
              </div>
              <div className="field">
                <label htmlFor="adjustment-note">Motivo</label>
                <textarea id="adjustment-note" name="note" rows={3} required />
              </div>
              <button className="primaryButton" type="submit">
                Registrar ajuste
              </button>
            </form>
          </PanelCard>
        </div>
      ) : null}

      {tab === "regularizacion" ? (
        <div className="panelGrid">
          <PanelCard
            title="Regularización histórica"
            description="Carga saldo o latas preexistentes sin afectar el retiro físico."
          >
            <form action={createRegularizationAction} className="formStack">
              <input name="createdByProfileId" type="hidden" value={user.id} />
              <div className="field">
                <label htmlFor="regularization-client">Cliente</label>
                <select id="regularization-client" name="clientProfileId" required>
                  <option value="">Selecciona un cliente</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName} · {client.rut}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="regularization-local">Código local</label>
                <input
                  defaultValue={clients[0]?.localCode ?? ""}
                  id="regularization-local"
                  name="localCode"
                  required
                  type="text"
                />
              </div>
              <div className="field">
                <label htmlFor="regularization-type">Tipo</label>
                <select id="regularization-type" name="type" required>
                  <option value="latas_preexistentes">Latas preexistentes</option>
                  <option value="saldo_preexistente">Saldo preexistente</option>
                  <option value="ajuste_excepcional">Ajuste excepcional</option>
                </select>
              </div>
              <div className="field">
                <label htmlFor="regularization-cans">Cantidad de latas</label>
                <input id="regularization-cans" min="0" name="canCount" type="number" />
              </div>
              <div className="field">
                <label htmlFor="regularization-vpc">Valor por lata</label>
                <input
                  defaultValue="10"
                  id="regularization-vpc"
                  min="0"
                  name="valuePerCan"
                  type="number"
                />
              </div>
              <div className="field">
                <label htmlFor="regularization-amount">Monto total</label>
                <input id="regularization-amount" min="0" name="amount" type="number" />
              </div>
              <div className="field">
                <label htmlFor="regularization-note">Motivo</label>
                <textarea id="regularization-note" name="note" rows={3} required />
              </div>
              <button className="primaryButton" type="submit">
                Registrar regularización
              </button>
            </form>
          </PanelCard>
        </div>
      ) : null}

      {tab === "duplicados" ? (
        <div className="panelGrid">
          <PanelCard
            title="RUT duplicados"
            description="Fusiona el perfil secundario sobre el principal, igual que el módulo legacy."
          >
            {duplicateGroups.length === 0 ? (
              <div className="infoBox">No se encontraron duplicados.</div>
            ) : (
              duplicateGroups.map((group) => (
                <div className="panelCard" key={group.rut}>
                  <h3>{group.rut}</h3>
                  <div className="tableWrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Nombre</th>
                          <th>Email</th>
                          <th>Movimientos</th>
                          <th>Local</th>
                        </tr>
                      </thead>
                      <tbody>
                        {group.users.map((groupUser) => (
                          <tr key={groupUser.id}>
                            <td>{groupUser.fullName}</td>
                            <td>{groupUser.email}</td>
                            <td>{group.movementCountByUser[groupUser.id] ?? 0}</td>
                            <td>{groupUser.localName ?? "—"}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {group.users.length >= 2 ? (
                    <form action={mergeClientsAction} className="formStack">
                      <input name="actorProfileId" type="hidden" value={user.id} />
                      <input
                        name="primaryProfileId"
                        type="hidden"
                        value={group.users[0].id}
                      />
                      <input
                        name="secondaryProfileId"
                        type="hidden"
                        value={group.users[1].id}
                      />
                      <button className="ghostButton" type="submit">
                        Fusionar segundo usuario en el primero
                      </button>
                    </form>
                  ) : null}
                </div>
              ))
            )}
          </PanelCard>
        </div>
      ) : null}

      {tab === "importar" ? (
        <div className="panelGrid">
          <PanelCard
            title="Importar CSV"
            description="Carga masiva para ajustes, incentivos o regularización."
          >
            <div className="infoBox">
              Columnas esperadas: `mode,rut,local_code,type,amount,note,can_count,value_per_can`
            </div>
            <form action={importCsvAction} className="formStack">
              <input name="actorProfileId" type="hidden" value={user.id} />
              <div className="field">
                <label htmlFor="csv-file">Archivo CSV</label>
                <input
                  accept=".csv,text/csv"
                  id="csv-file"
                  name="csvFile"
                  required
                  type="file"
                />
              </div>
              <button className="primaryButton" type="submit">
                Importar
              </button>
            </form>
          </PanelCard>
        </div>
      ) : null}
        </div>
      </div>
    </AppShell>
  );
}
