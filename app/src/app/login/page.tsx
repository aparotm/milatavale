import { loginAction } from "@/app/actions";

type LoginPageProps = {
  searchParams: Promise<{ error?: string }>;
};

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const params = await searchParams;
  const hasError = params.error === "1";

  return (
    <main className="loginPage">
      <section className="loginCard" style={{ maxWidth: 560 }}>
        <div
          style={{
            display: "grid",
            justifyItems: "center",
            gap: 20,
            marginBottom: 20,
          }}
        >
          <div className="brandMark">
            <span>MI LATA VALE</span>
          </div>
        </div>

        {hasError ? <div className="errorBox">Credenciales inválidas.</div> : null}

        <form className="formStack" action={loginAction}>
          <label className="field">
            <span>RUT</span>
            <input
              defaultValue="11.111.111-1"
              name="identifier"
              placeholder="12.345.678-9"
              required
              type="text"
            />
          </label>

          <label className="field">
            <span>Contraseña</span>
            <input
              defaultValue="demo123"
              name="password"
              placeholder="Contraseña"
              required
              type="password"
            />
          </label>

          <div className="infoBox">
            En esta etapa puedes entrar con RUT o email del usuario demo. La
            contraseña temporal sigue siendo `demo123`.
          </div>

          <button className="primaryButton" type="submit">
            Ingresar
          </button>
        </form>
      </section>
    </main>
  );
}
