import { ReactNode } from "react";

export function KpiCard({
  label,
  value,
  note,
}: {
  label: string;
  value: string;
  note?: string;
}) {
  return (
    <article className="kpiCard">
      <span className="kpiLabel">{label}</span>
      <strong className="kpiValue">{value}</strong>
      {note ? <small className="muted">{note}</small> : null}
    </article>
  );
}

export function PanelCard({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <section className="panelCard">
      <div className="panelCardHeader">
        <div>
          <h3>{title}</h3>
          {description ? <p className="muted">{description}</p> : null}
        </div>
      </div>
      {children}
    </section>
  );
}
