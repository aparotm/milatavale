import Link from "next/link";

import { publicRegistrationAction } from "@/app/actions";

type RegistroPageProps = {
  searchParams: Promise<{ role?: string; success?: string; error?: string }>;
};

function RoleLink({ href, label }: { href: string; label: string }) {
  return (
    <Link className="primaryButton" href={href}>
      {label}
    </Link>
  );
}

export default async function RegistroPage({ searchParams }: RegistroPageProps) {
  const params = await searchParams;
  const role =
    params.role === "almacen" || params.role === "gestor" ? params.role : "cliente";

  return (
    <main className="landing">
      <section className="landingHero">
        <div className="heroActions">
          <RoleLink href="/registro?role=cliente" label="Registro Cliente" />
          <RoleLink href="/registro?role=almacen" label="Registro Almacén" />
          <RoleLink href="/registro?role=gestor" label="Registro Gestor" />
        </div>

        {params.success ? <div className="successBox">{params.success}</div> : null}
        {params.error ? <div className="errorBox">{params.error}</div> : null}

        <section className="panelCard" style={{ marginTop: 20 }}>
          <h3>
            {role === "cliente"
              ? "Registro de cliente"
              : role === "almacen"
                ? "Registro de almacén"
                : "Registro de gestor"}
          </h3>

          <form action={publicRegistrationAction} className="formStack">
            <input name="role" type="hidden" value={role} />
            <div className="field">
              <label htmlFor="reg-full-name">Nombre completo</label>
              <input id="reg-full-name" name="fullName" required type="text" />
            </div>
            <div className="field">
              <label htmlFor="reg-rut">RUT</label>
              <input id="reg-rut" name="rut" required type="text" />
            </div>
            <div className="field">
              <label htmlFor="reg-phone">Teléfono</label>
              <input id="reg-phone" name="phone" type="text" />
            </div>
            <div className="field">
              <label htmlFor="reg-email">Email</label>
              <input id="reg-email" name="email" type="email" />
            </div>

            {role !== "cliente" ? (
              <>
                <div className="field">
                  <label htmlFor="reg-local-code">Código local</label>
                  <input id="reg-local-code" name="localCode" required type="text" />
                </div>
                <div className="field">
                  <label htmlFor="reg-local-name">Nombre local</label>
                  <input id="reg-local-name" name="localName" required type="text" />
                </div>
                <div className="field">
                  <label htmlFor="reg-comuna">Comuna</label>
                  <input id="reg-comuna" name="comuna" type="text" />
                </div>
                <div className="field">
                  <label htmlFor="reg-address">Dirección</label>
                  <input id="reg-address" name="address" type="text" />
                </div>
              </>
            ) : null}

            <button className="primaryButton" type="submit">
              Enviar registro
            </button>
          </form>
        </section>
      </section>
    </main>
  );
}
