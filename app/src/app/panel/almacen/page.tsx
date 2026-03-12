import Link from "next/link";

import {
  createClientAction,
  createGastoAction,
  createIngresoAction,
  previewGastoAction,
  previewIngresoAction,
  updateLocalProfileAction,
} from "@/app/actions";
import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import { EvidencePicker } from "@/components/evidence-picker";
import { TableSearch } from "@/components/table-search";
import { getLocalProfile, getMovementsForLocal, getUsers } from "@/lib/data";
import {
  formatCompactDate,
  formatMoney,
  movementLabel,
  statusLabel,
} from "@/lib/format";
import { requireSessionContext } from "@/lib/session";

type Params = Record<string, string | string[] | undefined>;

function getParam(params: Params | undefined, key: string) {
  const value = params?.[key];
  return typeof value === "string" ? value : "";
}

export default async function AlmacenPanelPage({
  searchParams,
}: {
  searchParams?: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { user, impersonator } = await requireSessionContext("almacen");
  const params = searchParams ? await searchParams : undefined;
  const tab = getParam(params, "tab") || "panel";
  const preview = getParam(params, "preview") === "1";
  const selectedClientId = getParam(params, "client");
  const movements = await getMovementsForLocal(user.localCode ?? "");
  const localProfile = await getLocalProfile(user.localCode ?? "");
  const users = await getUsers();
  const clients = users.filter(
    (appUser) =>
      appUser.role === "cliente" && appUser.localCode === user.localCode,
  );
  const success = getParam(params, "success");
  const error = getParam(params, "error");

  const generated = movements
    .filter((movement) => movement.amount > 0)
    .reduce((sum, movement) => sum + movement.amount, 0);
  const incentives = movements
    .filter((movement) => movement.type === "incentivo")
    .reduce((sum, movement) => sum + Math.max(movement.amount, 0), 0);
  const spent = movements
    .filter((movement) => movement.amount < 0)
    .reduce((sum, movement) => sum + Math.abs(movement.amount), 0);
  const cans = movements
    .filter((movement) => movement.type === "ingreso")
    .reduce((sum, movement) => sum + movement.canCount, 0);
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
        .filter((movement) => movement.amount > 0 && movement.type === "ingreso")
        .reduce((sum, movement) => sum + movement.amount, 0),
      incentives: clientMovements
        .filter((movement) => movement.type === "incentivo")
        .reduce((sum, movement) => sum + Math.max(movement.amount, 0), 0),
      canjeado: clientMovements
        .filter((movement) => movement.amount < 0)
        .reduce((sum, movement) => sum + Math.abs(movement.amount), 0),
      cans: clientMovements
        .filter((movement) => movement.type === "ingreso")
        .reduce((sum, movement) => sum + movement.canCount, 0),
      balance: clientMovements.reduce((sum, movement) => sum + movement.amount, 0),
      movements: clientMovements.length,
    };
  });

  const selectedClient =
    clientRows.find((client) => client.id === selectedClientId) ?? null;
  const visibleMovements = selectedClient
    ? movements.filter((movement) => movement.clientId === selectedClient.id)
    : movements;

  const previewIngresoClient = clients.find(
    (client) => client.id === getParam(params, "clientProfileId"),
  );
  const previewIngresoCount = Number.parseInt(getParam(params, "canCount"), 10) || 0;
  const previewIngresoAmount = previewIngresoCount * 10;
  const previewIngresoCurrentBalance = selectedClient
    ? selectedClient.balance
    : clientRows.find((client) => client.id === previewIngresoClient?.id)?.balance ?? 0;

  const previewGastoClient = clientRows.find(
    (client) => client.id === getParam(params, "clientProfileId"),
  );
  const previewGastoAmount = Number.parseInt(getParam(params, "amount"), 10) || 0;

  return (
    <AppShell
      title={`Hola, ${user.fullName.split(" ")[0] ?? user.fullName}`}
      subtitle=""
      user={user}
      adminViewer={impersonator}
      variant="frontend"
      actions={
        <div className="toolbar">
          <Link className="primaryButton" href="/panel/almacen?tab=ingreso">
            + Latas
          </Link>
          <Link className="primaryButton" href="/panel/almacen?tab=gasto">
            + Gasto
          </Link>
          <Link className="primaryButton" href="/panel/almacen?tab=cliente">
            + Cliente
          </Link>
          <Link className="primaryButton" href="/panel/almacen?tab=perfil">
            Perfil
          </Link>
        </div>
      }
    >
      {tab === "ingreso" ? (
        <PanelCard
          title="Registra una entrega de latas y asigna un cliente."
          description="Replica del flujo del plugin: revisar primero, confirmar después."
        >
          {success ? <div className="successBox">{success}</div> : null}
          {error ? <div className="errorBox">{error}</div> : null}

          {preview && previewIngresoClient && previewIngresoCount > 0 ? (
            <div className="previewBox">
              <strong>Confirma el registro</strong>
              <div className="previewMetrics">
                <div className="previewMetric">
                  <div className="muted">Cliente</div>
                  <strong>{previewIngresoClient.fullName}</strong>
                </div>
                <div className="previewMetric">
                  <div className="muted">Latas</div>
                  <strong>{previewIngresoCount}</strong>
                </div>
                <div className="previewMetric">
                  <div className="muted">Monto equivalente</div>
                  <strong>{formatMoney(previewIngresoAmount)}</strong>
                </div>
                <div className="previewMetric">
                  <div className="muted">Saldo después</div>
                  <strong>
                    {formatMoney(previewIngresoCurrentBalance + previewIngresoAmount)}
                  </strong>
                </div>
              </div>
              {getParam(params, "evidenceUrl") ? (
                <div className="imagePreview" style={{ marginBottom: 14 }}>
                  <img
                    alt="Evidencia subida"
                    src={getParam(params, "evidenceUrl")}
                  />
                </div>
              ) : null}

              <form action={createIngresoAction} className="formStack">
                <input name="localCode" type="hidden" value={user.localCode ?? ""} />
                <input name="createdByProfileId" type="hidden" value={user.id} />
                <input
                  name="clientProfileId"
                  type="hidden"
                  value={previewIngresoClient.id}
                />
                <input
                  name="canCount"
                  type="hidden"
                  value={String(previewIngresoCount)}
                />
                <input name="note" type="hidden" value={getParam(params, "note")} />
                <input
                  name="evidenceUrl"
                  type="hidden"
                  value={getParam(params, "evidenceUrl")}
                />
                <div className="formActions">
                  <button className="primaryButton" type="submit">
                    Confirmar y registrar
                  </button>
                  <Link className="ghostButton" href="/panel/almacen?tab=ingreso">
                    Volver
                  </Link>
                </div>
              </form>
            </div>
          ) : (
            <form action={previewIngresoAction} className="formStack">
              <div className="field">
                <label htmlFor="ingreso-client">Cliente</label>
                <select
                  defaultValue={getParam(params, "clientProfileId")}
                  id="ingreso-client"
                  name="clientProfileId"
                  required
                >
                  <option value="">Selecciona un cliente</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName} · {client.rut}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="ingreso-cans">Cantidad de latas</label>
                <input
                  defaultValue={getParam(params, "canCount")}
                  id="ingreso-cans"
                  min="1"
                  name="canCount"
                  required
                  type="number"
                />
              </div>
              <div className="field">
                <label htmlFor="ingreso-note">Observación</label>
                <textarea
                  defaultValue={getParam(params, "note")}
                  id="ingreso-note"
                  name="note"
                  rows={3}
                />
              </div>
              <EvidencePicker inputId="ingreso-evidence" inputName="evidence" />
              <button className="primaryButton" type="submit">
                Revisar registro
              </button>
            </form>
          )}
        </PanelCard>
      ) : null}

      {tab === "gasto" ? (
        <PanelCard
          title="Asígnale un gasto a un cliente"
          description="El gasto descuenta saldo del cliente y queda registrado en el ledger."
        >
          {success ? <div className="successBox">{success}</div> : null}
          {error ? <div className="errorBox">{error}</div> : null}

          {preview && previewGastoClient && previewGastoAmount > 0 ? (
            <div className="previewBox">
              <strong>Confirma el gasto</strong>
              <div className="previewMetrics">
                <div className="previewMetric">
                  <div className="muted">Cliente</div>
                  <strong>{previewGastoClient.fullName}</strong>
                </div>
                <div className="previewMetric">
                  <div className="muted">Saldo actual</div>
                  <strong>{formatMoney(previewGastoClient.balance)}</strong>
                </div>
                <div className="previewMetric">
                  <div className="muted">Monto gasto</div>
                  <strong>{formatMoney(previewGastoAmount)}</strong>
                </div>
                <div className="previewMetric">
                  <div className="muted">Saldo después</div>
                  <strong>
                    {formatMoney(previewGastoClient.balance - previewGastoAmount)}
                  </strong>
                </div>
              </div>
              {getParam(params, "evidenceUrl") ? (
                <div className="imagePreview" style={{ marginBottom: 14 }}>
                  <img
                    alt="Evidencia subida"
                    src={getParam(params, "evidenceUrl")}
                  />
                </div>
              ) : null}

              <form action={createGastoAction} className="formStack">
                <input name="localCode" type="hidden" value={user.localCode ?? ""} />
                <input name="createdByProfileId" type="hidden" value={user.id} />
                <input
                  name="clientProfileId"
                  type="hidden"
                  value={previewGastoClient.id}
                />
                <input name="amount" type="hidden" value={String(previewGastoAmount)} />
                <input name="note" type="hidden" value={getParam(params, "note")} />
                <input
                  name="evidenceUrl"
                  type="hidden"
                  value={getParam(params, "evidenceUrl")}
                />
                <div className="formActions">
                  <button className="primaryButton" type="submit">
                    Confirmar y descontar
                  </button>
                  <Link className="ghostButton" href="/panel/almacen?tab=gasto">
                    Volver
                  </Link>
                </div>
              </form>
            </div>
          ) : (
            <form action={previewGastoAction} className="formStack">
              <div className="field">
                <label htmlFor="gasto-client">Cliente</label>
                <select
                  defaultValue={getParam(params, "clientProfileId")}
                  id="gasto-client"
                  name="clientProfileId"
                  required
                >
                  <option value="">Selecciona un cliente</option>
                  {clientRows.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName} · máximo {formatMoney(client.balance)}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label htmlFor="gasto-amount">Monto del gasto ($)</label>
                <input
                  defaultValue={getParam(params, "amount")}
                  id="gasto-amount"
                  min="1"
                  name="amount"
                  required
                  type="number"
                />
              </div>
              <div className="field">
                <label htmlFor="gasto-note">Motivo / detalle</label>
                <textarea
                  defaultValue={getParam(params, "note")}
                  id="gasto-note"
                  name="note"
                  rows={3}
                />
              </div>
              <EvidencePicker inputId="gasto-evidence" inputName="evidence" />
              <button className="primaryButton" type="submit">
                Revisar gasto
              </button>
            </form>
          )}
        </PanelCard>
      ) : null}

      {tab === "cliente" ? (
        <PanelCard
          title="Registra tu cliente a tu almacén"
          description="Alta manual previa al registro público por formulario."
        >
          {success ? <div className="successBox">{success}</div> : null}
          {error ? <div className="errorBox">{error}</div> : null}
          <form action={createClientAction} className="formStack">
            <input name="localCode" type="hidden" value={user.localCode ?? ""} />
            <input name="createdByProfileId" type="hidden" value={user.id} />
            <div className="field">
              <label htmlFor="client-full-name">Nombre completo</label>
              <input id="client-full-name" name="fullName" required type="text" />
            </div>
            <div className="field">
              <label htmlFor="client-rut">RUT</label>
              <input id="client-rut" name="rut" required type="text" />
            </div>
            <div className="field">
              <label htmlFor="client-phone">Teléfono</label>
              <input id="client-phone" name="phone" type="text" />
            </div>
            <div className="field">
              <label htmlFor="client-email">Email (opcional)</label>
              <input id="client-email" name="email" type="email" />
            </div>
            <button className="primaryButton" type="submit">
              Registrar cliente
            </button>
          </form>
        </PanelCard>
      ) : null}

      {tab === "panel" ? (
        <>
          <PanelCard title="Ver por cliente">
            <form className="formStack" method="get">
              <input name="tab" type="hidden" value="panel" />
              <div className="field">
                <select
                  defaultValue={selectedClientId}
                  name="client"
                  onChange={undefined}
                >
                  <option value="">Todos los clientes</option>
                  {clientRows.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.fullName}
                    </option>
                  ))}
                </select>
              </div>
              <button className="secondaryButton" type="submit">
                Aplicar filtro
              </button>
            </form>
          </PanelCard>

          <div className="kpiGrid">
            <KpiCard
              label="Saldo disponible"
              value={formatMoney(
                selectedClient
                  ? selectedClient.balance
                  : clientRows.reduce((sum, client) => sum + client.balance, 0),
              )}
            />
            <KpiCard
              label="Generado por reciclaje"
              value={formatMoney(selectedClient ? selectedClient.generated : generated)}
            />
            <KpiCard
              label="Generado por incentivos"
              value={formatMoney(
                selectedClient ? selectedClient.incentives : incentives,
              )}
            />
            <KpiCard
              label="Canjeado"
              value={formatMoney(selectedClient ? selectedClient.canjeado : spent)}
            />
          </div>

          <div className="kpiGrid">
            <KpiCard
              label="Latas"
              value={String(selectedClient ? selectedClient.cans : cans)}
            />
            <KpiCard
              label="Movimientos"
              value={String(
                selectedClient ? selectedClient.movements : visibleMovements.length,
              )}
            />
            <KpiCard label="Pendientes retiro" value={String(pending.length)} />
            <KpiCard label="Local" value={user.localName ?? "—"} note={user.localCode} />
          </div>

          <PanelCard title="Buscar en movimientos">
            <TableSearch tableId="almacen-movimientos" />
          </PanelCard>

          <PanelCard
            title="Tus clientes"
            description={
              selectedClient
                ? `Filtrados para ${selectedClient.fullName}.`
                : "Generado, canjeado y consolidado por cliente en este local."
            }
          >
            <div className="tableWrap" style={{ marginBottom: 16 }}>
              <table>
                <thead>
                  <tr>
                    <th>Nombre completo</th>
                    <th>RUT</th>
                    <th>Teléfono</th>
                    <th>Ingresos reciclaje</th>
                    <th>Ingresos incentivos</th>
                    <th>Canjeado</th>
                    <th>Saldo</th>
                    <th>Latas</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {clientRows.map((client) => (
                    <tr key={client.id}>
                      <td>{client.fullName}</td>
                      <td>{client.rut}</td>
                      <td>{client.phone ?? "—"}</td>
                      <td>{formatMoney(client.generated)}</td>
                      <td>{formatMoney(client.incentives)}</td>
                      <td>{formatMoney(client.canjeado)}</td>
                      <td>{formatMoney(client.balance)}</td>
                      <td>{client.cans}</td>
                      <td>
                        <Link
                          className="ghostButton"
                          href={`/panel/almacen?tab=panel&client=${client.id}`}
                        >
                          {selectedClient?.id === client.id ? "Viendo" : "Ver"}
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="tableWrap">
              <table id="almacen-movimientos">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Nombre local</th>
                    <th>RUT</th>
                    <th>Latas</th>
                    <th>Valor por lata</th>
                    <th>Evidencia</th>
                    <th>Monto</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleMovements.map((movement) => (
                    <tr
                      data-search={[
                        movement.createdAt,
                        movement.type,
                        movement.clientName,
                        movement.clientRut,
                        movement.note ?? "",
                      ]
                        .join(" ")
                        .toLowerCase()}
                      key={movement.id}
                    >
                      <td>{formatCompactDate(movement.createdAt)}</td>
                      <td>{movementLabel(movement.type)}</td>
                      <td>{user.localName ?? movement.localName ?? movement.localCode}</td>
                      <td>{movement.clientRut}</td>
                      <td>{movement.type === "gasto" ? "—" : movement.canCount || "—"}</td>
                      <td>{movement.type === "gasto" ? "—" : "$10"}</td>
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
        </>
      ) : null}

      {tab === "perfil" && localProfile ? (
        <PanelCard
          title="Tus Datos"
          description="Ficha operativa del local y horario de atención."
        >
          {success ? <div className="successBox">{success}</div> : null}
          {error ? <div className="errorBox">{error}</div> : null}
          <form action={updateLocalProfileAction} className="formStack">
            <input name="localCode" type="hidden" value={user.localCode ?? ""} />
            <input name="actorProfileId" type="hidden" value={user.id} />
            <div className="field">
              <label htmlFor="local-name">Nombre del local</label>
              <input
                defaultValue={localProfile.name}
                id="local-name"
                name="name"
                required
                type="text"
              />
            </div>
            <div className="field">
              <label htmlFor="local-comuna">Comuna</label>
              <input
                defaultValue={localProfile.comuna ?? ""}
                id="local-comuna"
                name="comuna"
                type="text"
              />
            </div>
            <div className="field">
              <label htmlFor="local-address">Dirección del local</label>
              <input
                defaultValue={localProfile.address ?? ""}
                id="local-address"
                name="address"
                type="text"
              />
            </div>
            <div className="field">
              <label htmlFor="local-phone">Teléfono</label>
              <input
                defaultValue={localProfile.phone ?? ""}
                id="local-phone"
                name="phone"
                type="text"
              />
            </div>

            <PanelCard title="Horario de atención">
              <div className="panelGrid">
                {localProfile.hours.map((hour, index) => (
                  <div className="actionCard" key={hour.day}>
                    <div
                      style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        gap: 12,
                        marginBottom: 12,
                      }}
                    >
                      <strong>{hour.day}</strong>
                      <label
                        style={{ display: "flex", alignItems: "center", gap: 8 }}
                      >
                        <input
                          defaultChecked={hour.open}
                          name={`day_${index}_open`}
                          type="checkbox"
                          value="1"
                        />
                        Abierto
                      </label>
                    </div>
                    <input name={`day_${index}_label`} type="hidden" value={hour.day} />
                    <div className="field">
                      <label>De</label>
                      <input
                        defaultValue={hour.from}
                        name={`day_${index}_from`}
                        type="time"
                      />
                    </div>
                    <div className="field">
                      <label>A</label>
                      <input
                        defaultValue={hour.to}
                        name={`day_${index}_to`}
                        type="time"
                      />
                    </div>
                  </div>
                ))}
              </div>
            </PanelCard>

            <button className="primaryButton" type="submit">
              Guardar perfil
            </button>
          </form>
        </PanelCard>
      ) : null}
    </AppShell>
  );
}
