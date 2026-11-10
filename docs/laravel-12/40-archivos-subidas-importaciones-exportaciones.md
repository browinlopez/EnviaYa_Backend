# 40 — Archivos, importaciones y exportaciones seguras

**Estado:** requerido si la API recibe PDF, imágenes, facturas, Excel o archivos de clientes.

## Flujo recomendado

1. `FormRequest`: validar presencia, tamaño máximo, formato esperado y límites.
2. `Action`: autorizar tenant y registrar metadatos; asignar ruta interna segura.
3. `Storage`: guardar en disco/bucket privado por defecto, con permisos mínimos.
4. `Job`: procesamiento costoso (OCR, transformación, parsing, antivirus si aplica).
5. `Resource`: devolver estado y enlace temporal **solo si el usuario está autorizado**.

## Estructura por funcionalidad

```text
app/Modules/Invoices/
  Http/Controllers/UploadInvoiceController.php
  Http/Requests/UploadInvoiceRequest.php
  Actions/RegisterInvoiceUpload.php
  Jobs/ParseInvoiceFile.php
  Models/InvoiceImport.php
  Queries/ListInvoiceImports.php
```

## Riesgos

- Nunca usar nombre del archivo del usuario como ruta ejecutable; usar identificadores generados.
- Validar contenido real y extensión cuando importe; no confiar solo en MIME declarado por cliente.
- Protección frente a ZIP bombs, CSV formula injection, macros, cargas masivas y parsing de XML externo cuando corresponda.
- Limitar tamaño, número de filas, duración y memoria; implementar procesamiento por lotes.
- Evitar hacer archivos públicos por defecto; las URL firmadas temporales tienen expiración y alcance apropiado.
- Registrar autor, tenant, estado, errores recuperables y fecha de retención.
- Considerar detección de malware o aislamiento de archivos según riesgos del negocio.

## Eficiencia

- Archivos grandes: subida directa a object storage mediante URL firmada cuando el diseño lo permita.
- Exportación grande: generar Job y notificar resultado; no montar todo el dataset en RAM.
- Importaciones: validar por lotes, usar `upsert` cuando semántica y constraints lo permitan, registrar errores por fila.

**Fuente:** https://laravel.com/docs/12.x/filesystem · https://laravel.com/docs/12.x/validation · https://laravel.com/docs/12.x/queues
