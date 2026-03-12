import { AppShell } from "@/components/shell";
import { KpiCard, PanelCard } from "@/components/cards";
import {
  getClientBalance,
  getMovementsForClient,
} from "@/lib/data";
import { requireSessionUser } from "@/lib/session";

export default async function ClientePanelPage() {
  const user = await requireSessionUser("cliente");
  const movements = await getMovementsForClient(user.id);
  const balance = await getClientBalance(user.id);
  const generated = movements
    .filter((movement) => movement.amount > 0)
    .reduce((sum, movement) => sum + movement.amount, 0);
  const spent = movements
    .filter((movement) => movement.amount < 0)
    .reduce((sum, movement) => sum + Math.abs(movement.amount), 0);
  const cans = movements.reduce((sum, movement) => sum + movement.canCount, 0);

  return (
    <AppShell
      title="Panel Cliente"
      subtitle="Resumen de saldo, movimientos y actividad del cliente."
      user={user}
    >
      <div className="kpiGrid">
        <KpiCard label="Saldo disponible" value={`$${balance}`} />
        <KpiCard label="Generado" value={`$${generated}`} />
        <KpiCard label="Canjeado" value={`$${spent}`} />
        <KpiCard label="Latas" value={String(cans)} />
      </div>

      <div className="panelGrid">
        <PanelCard
          title="Perfil"
          description="En la siguiente iteración aquí irá edición de datos y alertas."
        >
          <div className="tableWrap">
            <table>
              <tbody>
                <tr>
                  <th>Nombre</th>
                  <td>{user.fullName}</td>
                </tr>
                <tr>
                  <th>RUT</th>
                  <td>{user.rut}</td>
                </tr>
                <tr>
                  <th>Local</th>
                  <td>{user.localName}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </PanelCard>

        <PanelCard title="Movimientos">
          <div className="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tipo</th>
                  <th>Latas</th>
                  <th>Monto</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((movement) => (
                  <tr key={movement.id}>
                    <td>{movement.createdAt}</td>
                    <td>{movement.type}</td>
                    <td>{movement.canCount || "—"}</td>
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
