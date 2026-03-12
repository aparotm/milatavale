import { LedgerMovement } from "@/lib/types";

export function formatMoney(amount: number) {
  return new Intl.NumberFormat("es-CL", {
    style: "currency",
    currency: "CLP",
    maximumFractionDigits: 0,
  }).format(amount);
}

export function formatCompactDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("es-CL", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

export function movementLabel(type: LedgerMovement["type"]) {
  switch (type) {
    case "gasto":
      return "Gasto";
    case "incentivo":
      return "Incentivo";
    case "ajuste":
      return "Ajuste";
    default:
      return "Ingreso";
  }
}

export function statusLabel(status: LedgerMovement["status"]) {
  return status === "pendiente_retiro" ? "Pendiente retiro" : "Retirado";
}
