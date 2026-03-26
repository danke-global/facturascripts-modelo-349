# Modelo 349 para FacturaScripts

Plugin para [FacturaScripts](https://facturascripts.com) que genera el **Modelo 349** — Declaración recapitulativa de operaciones intracomunitarias.

## Funcionalidades

- Visualización de adquisiciones intracomunitarias (clave A) y entregas intracomunitarias (clave E)
- Filtrado por ejercicio y período (trimestral o anual)
- Generación de fichero `.349` en formato BOE para presentación telemática ante la AEAT
- Exclusión automática de operadores no comunitarios (NIF con prefijo EU/OSS)
- Soporte multi-empresa

## Requisitos

- FacturaScripts 2025.6 o superior
- Las facturas intracomunitarias deben tener el campo `operacion` establecido como `intracomunitaria`
- Los proveedores/clientes intracomunitarios deben tener su NIF con el prefijo del país (ej: `IE3668997OH`, `DE123456789`)

## Instalación

1. Descarga o clona este repositorio en la carpeta `Plugins/Modelo349` de tu instalación de FacturaScripts:
   ```bash
   cd /var/www/html/Plugins
   git clone https://github.com/glickman/facturascripts-modelo-349.git Modelo349
   ```
2. Ve a **Administración > Plugins** y activa el plugin **Modelo349**
3. El modelo aparecerá en el menú **Informes > Modelo 349**

## Uso

1. Selecciona el ejercicio y el período (trimestre o anual)
2. Pulsa **Vista previa** para ver las operaciones
3. Pulsa **Descargar .349** para generar el fichero en formato BOE

## Estructura del fichero .349

El fichero generado sigue las especificaciones de la AEAT:

- **Registro tipo 1**: Datos del declarante (500 caracteres)
- **Registro tipo 2**: Datos de cada operador intracomunitario (500 caracteres)
- Codificación: ISO-8859-1
- Separador de registros: CR+LF

## Notas sobre operadores no comunitarios

Los operadores con NIF que no correspondan a un estado miembro de la UE se excluyen automáticamente del modelo. Por ejemplo, empresas con NIF con prefijo `EU` (registro OSS/One Stop Shop) como OpenAI OpCo, LLC (`EU372041333`) no se incluyen, ya que el Modelo 349 solo contempla operaciones intracomunitarias entre estados miembros.

## Licencia

MIT
