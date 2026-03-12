import Link from "next/link";

import { getMovements, getUsers } from "@/lib/data";

export default async function HomePage() {
  const users = await getUsers();
  const movements = await getMovements();

  return (
    <main className="landing">
      <section className="landingHero">
        <div className="heroGrid">
          <div>
            <p className="eyebrow">Migración en curso</p>
            <h1 className="heroTitle">Mi Lata Vale App</h1>
            <p className="heroText">
              Primera réplica navegable del plugin WordPress, pensada para
              validar paneles, roles y lógica antes de migrar datos reales.
            </p>

            <div className="heroActions">
              <Link className="primaryButton" href="/login">
                Entrar al prototipo
              </Link>
              <Link className="secondaryButton" href="/registro">
                Registro público
              </Link>
              <Link className="secondaryButton" href="/panel/admin">
                Ver panel admin demo
              </Link>
            </div>
          </div>

          <div className="panelCard">
            <h3>Base funcional rescatada</h3>
            <ul className="heroList">
              <li>Ledger de movimientos como fuente de verdad</li>
              <li>Roles: admin, almacén, cliente y gestor</li>
              <li>Paneles separados por rol</li>
              <li>KPIs por usuario y local</li>
              <li>Estados logísticos y saldo contable diferenciados</li>
              <li>Registro público por tipo de usuario</li>
              <li>Importación y exportación CSV para admin</li>
            </ul>
          </div>
        </div>

        <div className="panelGrid">
          <section className="panelCard">
            <h3>Usuarios de prueba</h3>
            <div className="tableWrap">
              <table>
                <thead>
                  <tr>
                    <th>Rol</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Password</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.id}>
                      <td>
                        <span className="tag">{user.role}</span>
                      </td>
                      <td>{user.fullName}</td>
                      <td>{user.email}</td>
                      <td>{user.password ?? "demo123"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="panelCard">
            <h3>Dataset demo inicial</h3>
            <p className="muted">
              El prototipo parte con {users.length} usuarios y{" "}
              {movements.length} movimientos para validar los paneles.
            </p>
          </section>
        </div>
      </section>
    </main>
  );
}
