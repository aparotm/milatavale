"use client";

import { useState } from "react";

export function EvidencePicker({
  inputId,
  inputName,
}: {
  inputId: string;
  inputName: string;
}) {
  const [previewUrl, setPreviewUrl] = useState("");
  const [fileName, setFileName] = useState("");

  return (
    <div className="field">
      <label htmlFor={inputId}>Evidencia (opcional)</label>
      <input
        accept="image/*"
        id={inputId}
        name={inputName}
        type="file"
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (!file) {
            setPreviewUrl("");
            setFileName("");
            return;
          }

          setFileName(file.name);
          setPreviewUrl(URL.createObjectURL(file));
        }}
      />
      {fileName ? <div className="muted">{fileName}</div> : null}
      {previewUrl ? (
        <div className="imagePreview">
          <img alt="Previsualización de evidencia" src={previewUrl} />
        </div>
      ) : null}
    </div>
  );
}
