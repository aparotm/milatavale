import {
  createClientAction,
  createGastoAction,
  createIngresoAction,
} from "@/app/actions";
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

export default async function AlmacenPanelPage({
  searchParams,
}: {
  searchParams?: Promise<Record<string, string | string[] | undefined>>;
}) {
  const user = await requireSessionUser("almacen");
  const params = searchParams ? await searchParams : undefined;
  const movements = await getMovementsForLocal(user.localCode ?? "");
  const users = await getUsers();
  const clients = users.filter(
    (appUser) =>
      appUser.role === "cliente" && appUser.localCode === user.localCode,
  );
  const success =
    typeof params?.success === "string" ? params.success : undefined;
  const error = typeof params?.error === "string" ? params.error : undefined;

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
          title="Registrar latas"
          description="Crea un ingreso real en el ledger con estado pendiente de retiro."
        >
          <form action={createIngresoAction} className="formStack">
            <input name="localCode" type="hidden" value={user.localCode ?? ""} />
            <input
              name="createdByProfileId"
              type="hidden"
              value={user.id}
            />
            <div className="field">
              <label htmlFor="ingreso-client">Cliente</label>
              <select id="ingreso-client" name="clientProfileId" required>
                <option value="">Seleccionar cliente</option>
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
                id="ingreso-cans"
                min="1"
                name="canCount"
                required
                type="number"
              />
            </div>
            <div className="field">
              <label htmlFor="ingreso-note">Nota</label>
              <textarea
                id="ingreso-note"
                name="note"
                placeholder="Registro de latas del almacén"
                rows={3}
              />
            </div>
            <button className="primaryButton" type="submit">
              Guardar ingreso
            </button>
          </form>
        </PanelCard>

        <PanelCard
          title="Registrar gasto"
          description="Descuenta saldo disponible del cliente de forma inmediata."
        >
          <form action={createGastoAction} className="formStack">
            <input name="localCode" type="hidden" value={user.localCode ?? ""} />
            <input
              name="createdByProfileId"
              type="hidden"
              value={user.id}
            />
            <div className="field">
              <label htmlFor="gasto-client">Cliente</label>
              <select id="gasto-client" name="clientProfileId" required>
                <option value="">Seleccionar cliente</option>
                {clientRows.map((client) => (
                  <option key={client.id} value={client.id}>
                    {client.fullName} · saldo {formatMoney(client.balance)}
                  </option>
                ))}
              </select>
            </div>
            <div className="field">
              <label htmlFor="gasto-amount">Monto</label>
              <input
                id="gasto-amount"
                min="1"
                name="amount"
                required
                type="number"
              />
            </div>
            <div className="field">
              <label htmlFor="gasto-note">Detalle</label>
              <textarea
                id="gasto-note"
                name="note"
                placeholder="Canje de saldo"
                rows={3}
              />
            </div>
            <button className="primaryButton" type="submit">
              Guardar gasto
            </button>
          </form>
        </PanelCard>

        <PanelCard
          title="Registrar cliente"
          description="Alta simple para poblar el local antes del registro público."
        >
          <form action={createClientAction} className="formStack">
            <input name="localCode" type="hidden" value={user.localCode ?? ""} />
            <input
              name="createdByProfileId"
              type="hidden"
              value={user.id}
            />
            <div className="field">
              <label htmlFor="client-full-name">Nombre completo</label>
              <input id="client-full-name" name="fullName" required type="text" />
            </div>
            <div className="field">
              <label htmlFor="client-rut">RUT</label>
              <input id="client-rut" name="rut" required type="text" />
            </div>
            <div className="field">
              <label htmlFor="client-email">Email</label>
              <input id="client-email" name="email" type="email" />
            </div>
            <div className="field">
              <label htmlFor="client-phone">Teléfono</label>
              <input id="client-phone" name="phone" type="text" />
            </div>
            <button className="primaryButton" type="submit">
              Crear o asociar cliente
            </button>
          </form>
        </PanelCard>

        <PanelCard
          title="Clientes asociados"
          description="Saldo y actividad resumida por cliente."
        >
          {success ? <div className="successBox">{success}</div> : null}
          {error ? <div className="errorBox">{error}</div> : null}
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
                  <th>Detalle</th>
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
                    <td>{movement.note ?? "—"}</td>
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
