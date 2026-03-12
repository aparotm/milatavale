import { loginAction } from "@/app/actions";

type LoginPageProps = {
  searchParams: Promise<{ error?: string }>;
};

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const params = await searchParams;
  const hasError = params.error === "1";

  return (
    <main className="loginPage">
      <section className="loginCard">
        <div className="loginGrid">
          <div>
            <p className="eyebrow">Acceso demo</p>
            <h1 className="loginTitle">Entrar al prototipo</h1>
            <p className="muted">
              Esta etapa usa usuarios de prueba y login simple. El registro
              público y auth definitiva se implementarán después de validar la
              réplica funcional.
            </p>
          </div>

          <div>
            {hasError ? (
              <div className="errorBox">Credenciales inválidas.</div>
            ) : null}

            <form className="formStack" action={loginAction}>
              <label className="field">
                <span>Email</span>
                <input
                  defaultValue="admin@milatavale.app"
                  name="email"
                  type="email"
                  required
                />
              </label>

              <label className="field">
                <span>Password</span>
                <input
                  defaultValue="demo123"
                  name="password"
                  type="password"
                  required
                />
              </label>

              <button className="primaryButton" type="submit">
                Ingresar
              </button>
            </form>
          </div>
        </div>
      </section>
    </main>
  );
}
