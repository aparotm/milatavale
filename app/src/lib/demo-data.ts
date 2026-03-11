import { DemoUser, LedgerMovement } from "@/lib/types";

export const demoUsers: DemoUser[] = [
  {
    id: "admin-1",
    email: "admin@milatavale.app",
    password: "admin123",
    role: "admin",
    fullName: "Admin Mi Lata Vale",
    rut: "11.111.111-1",
  },
  {
    id: "almacen-1",
    email: "almacen.centro@milatavale.app",
    password: "almacen123",
    role: "almacen",
    fullName: "Carla Mendoza",
    rut: "12.345.678-5",
    localCode: "LOC-000101",
    localName: "Almacen Centro",
  },
  {
    id: "gestor-1",
    email: "gestor.centro@milatavale.app",
    password: "gestor123",
    role: "gestor",
    fullName: "Diego Soto",
    rut: "15.555.444-3",
    localCode: "LOC-000101",
    localName: "Almacen Centro",
  },
  {
    id: "cliente-1",
    email: "cliente.ana@milatavale.app",
    password: "cliente123",
    role: "cliente",
    fullName: "Ana Torres",
    rut: "16.403.938-8",
    localCode: "LOC-000101",
    localName: "Almacen Centro",
  },
  {
    id: "cliente-2",
    email: "cliente.pedro@milatavale.app",
    password: "cliente123",
    role: "cliente",
    fullName: "Pedro Rojas",
    rut: "17.654.321-K",
    localCode: "LOC-000101",
    localName: "Almacen Centro",
  },
];

export const demoMovements: LedgerMovement[] = [
  {
    id: "mov-001",
    type: "ingreso",
    clientId: "cliente-1",
    localCode: "LOC-000101",
    clientName: "Ana Torres",
    clientRut: "16.403.938-8",
    canCount: 42,
    amount: 420,
    status: "pendiente_retiro",
    createdAt: "2026-03-08 10:30",
    note: "Registro de latas del almacén",
  },
  {
    id: "mov-002",
    type: "gasto",
    clientId: "cliente-1",
    localCode: "LOC-000101",
    clientName: "Ana Torres",
    clientRut: "16.403.938-8",
    canCount: 0,
    amount: -150,
    status: "retirado",
    createdAt: "2026-03-09 12:10",
    note: "Canje de saldo",
  },
  {
    id: "mov-003",
    type: "ingreso",
    clientId: "cliente-2",
    localCode: "LOC-000101",
    clientName: "Pedro Rojas",
    clientRut: "17.654.321-K",
    canCount: 70,
    amount: 700,
    status: "retirado",
    createdAt: "2026-03-10 09:15",
    note: "Carga de latas",
  },
  {
    id: "mov-004",
    type: "incentivo",
    clientId: "cliente-2",
    localCode: "LOC-000101",
    clientName: "Pedro Rojas",
    clientRut: "17.654.321-K",
    canCount: 0,
    amount: 200,
    status: "retirado",
    createdAt: "2026-03-10 17:40",
    note: "Incentivo por campaña",
  }
];

export function getUserById(id: string) {
  return demoUsers.find((user) => user.id === id) ?? null;
}

export function getUserByCredentials(email: string, password: string) {
  return (
    demoUsers.find(
      (user) =>
        user.email.toLowerCase() === email.toLowerCase() &&
        user.password === password,
    ) ?? null
  );
}

export function getMovementsForClient(clientId: string) {
  return demoMovements.filter((movement) => movement.clientId === clientId);
}

export function getMovementsForLocal(localCode: string) {
  return demoMovements.filter((movement) => movement.localCode === localCode);
}

export function getClientBalance(clientId: string) {
  return getMovementsForClient(clientId).reduce(
    (sum, movement) => sum + movement.amount,
    0,
  );
}
