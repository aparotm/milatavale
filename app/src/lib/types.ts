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
  clientName: string;
  clientRut: string;
  canCount: number;
  amount: number;
  status: "pendiente_retiro" | "retirado";
  createdAt: string;
  note?: string;
};
