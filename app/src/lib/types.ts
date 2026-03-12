export type UserRole = "admin" | "almacen" | "cliente" | "gestor";

export type DemoUser = {
  id: string;
  email: string;
  password: string;
  role: UserRole;
  fullName: string;
  rut: string;
  localCode?: string;
  localName?: string;
};

export type AppUser = {
  id: string;
  email: string;
  password?: string;
  role: UserRole;
  fullName: string;
  rut: string;
  localCode?: string;
  localName?: string;
};

export type LedgerMovement = {
  id: string;
  type: "ingreso" | "gasto" | "incentivo" | "ajuste";
  clientId: string;
  localCode: string;
  localName?: string;
  clientName: string;
  clientRut: string;
  canCount: number;
  amount: number;
  status: "pendiente_retiro" | "retirado";
  createdAt: string;
  note?: string;
  evidenceUrl?: string;
};

export type AuditEntry = {
  id: string;
  action: string;
  objectType: string;
  objectId: string;
  actorName: string;
  createdAt: string;
  metadata?: Record<string, unknown>;
};

export type LocalHours = {
  day: string;
  open: boolean;
  from: string;
  to: string;
};

export type LocalProfile = {
  id: string;
  code: string;
  name: string;
  comuna?: string;
  address?: string;
  phone?: string;
  hours: LocalHours[];
};

export type DiagnosticsSummary = {
  duplicateRutCount: number;
  inactiveUsers: number;
  usersWithoutLocal: number;
  pendingMovements: number;
};
