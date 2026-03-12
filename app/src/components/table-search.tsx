"use client";

import { useEffect, useState } from "react";

export function TableSearch({
  tableId,
  placeholder = "Buscar...",
  emptyMessage = "No hay resultados.",
}: {
  tableId: string;
  placeholder?: string;
  emptyMessage?: string;
}) {
  const [value, setValue] = useState("");

  useEffect(() => {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = Array.from(table.querySelectorAll("tbody tr"));
    let visibleCount = 0;

    rows.forEach((row) => {
      const haystack = (row.getAttribute("data-search") ?? "").toLowerCase();
      const visible = value.trim() === "" || haystack.includes(value.toLowerCase());
      (row as HTMLElement).style.display = visible ? "" : "none";
      if (visible) {
        visibleCount += 1;
      }
    });

    const empty = document.getElementById(`${tableId}-empty`);
    if (empty) {
      empty.textContent = visibleCount === 0 ? emptyMessage : "";
    }
  }, [emptyMessage, tableId, value]);

  return (
    <div style={{ marginBottom: 14 }}>
      <input
        className="searchInput"
        onChange={(event) => setValue(event.target.value)}
        placeholder={placeholder}
        value={value}
      />
      <div className="muted" id={`${tableId}-empty`} style={{ marginTop: 8 }} />
    </div>
  );
}
