# Indicadores de aprendizaje (`report_indicadoresdocentes`)

Primera versión de un informe de curso de solo lectura para Moodle 4.5. Consulta datos estándar y no crea tablas propias.

Este repositorio contiene únicamente el plugin institucional en desarrollo. No incluye credenciales, copias de seguridad, bases de datos, datos sintéticos, `moodledata` ni el núcleo de Moodle.

## Indicadores incluidos

- Matrículas activas y actividades visibles del curso.
- Evidencia de participación: entrega enviada en tareas, publicación o respuesta en foros, e intento iniciado en cuestionarios.
- Aprobación por actividad y del curso.
- Finalización oficial configurada en Moodle.
- Eventos registrados por actividad, estudiante y día.
- Detalle nominal protegido por una capacidad independiente.

Los cursos en progreso abren por defecto en los últimos 30 días. Los cursos finalizados abren en
`Todo el curso`, para que sus interacciones históricas sean visibles sin cambiar manualmente el filtro.

Las interacciones son eventos atómicos, no tiempo de permanencia. Los datos históricos dependen del almacén de logs estándar y de su política de conservación. Las tasas usan matrículas no suspendidas, incluso si su periodo ya terminó; no reconstruyen quién debía participar en una fecha pasada.

## Permisos

- `report/indicadoresdocentes:view`: informe agregado. Permitido inicialmente a docente, docente editor y gestor.
- `report/indicadoresdocentes:viewdetails`: tabla nominal. Permitido inicialmente a docente editor y gestor.

Moodle permite modificar o anular estas capacidades por sistema, categoría, curso o usuario. Los estudiantes no reciben acceso inicialmente.

## Aprobación

El informe respeta `gradepass` cuando el elemento de calificación lo define. En caso contrario convierte la nota institucional predeterminada (3,0 sobre 5,0) al rango numérico del elemento. Ambos valores se configuran en Administración del sitio > Plugins > Informes > Indicadores de aprendizaje.

## Instalación en otro Moodle

1. Comprimir la carpeta `indicadoresdocentes` como un ZIP cuya raíz sea esa misma carpeta.
2. Instalarlo desde Administración del sitio > Plugins > Instalar plugins, o copiarlo a `report/indicadoresdocentes`.
3. Completar la actualización de la base de datos. No se crean tablas; Moodle registra el componente, su configuración y sus capacidades.

El laboratorio monta esta carpeta directamente en `/var/www/html/report/indicadoresdocentes` para mantener el código fuente fuera del núcleo ignorado por Git.

## Desinstalación

La desinstalación elimina el registro, capacidades y configuración del plugin. No elimina ni modifica cursos, usuarios, matrículas, entregas, intentos, publicaciones, calificaciones, finalización o logs. Después se retira la carpeta `report/indicadoresdocentes` (o su montaje en el laboratorio).

## Límites de esta versión

- Las entregas grupales de tareas requieren una validación adicional para configuraciones de entrega en equipo.
- El intento de cuestionario cuenta desde que se inicia, incluso si queda en curso. La calificación permite distinguir posteriormente el resultado evaluado.
- Las actividades distintas de tarea, foro y cuestionario usan la finalización oficial como evidencia primaria.
- El periodo completo agrega todo el historial conservado, pero su gráfico diario muestra como máximo los 93 días más recientes para mantener acotado el coste de consulta.
- No hay exportación CSV en esta versión.

## Seguridad y privacidad

- Todas las páginas exigen autenticación y la capacidad de curso `report/indicadoresdocentes:view`.
- El detalle nominal requiere además `report/indicadoresdocentes:viewdetails`.
- La selección de grupos se limita a los grupos que Moodle permite consultar al usuario actual.
- Las consultas utilizan Moodle DML con parámetros; el plugin no ejecuta SQL externo ni escribe datos académicos.
- La disponibilidad y conservación del historial depende de la configuración del almacén de logs de cada instalación.

Antes de desplegarlo en producción deben revisarse los roles, capacidades, política institucional de tratamiento de datos y retención de logs.

## Estado y compatibilidad

La versión actual es `0.1.1`, con madurez alpha, desarrollada y validada en Moodle 4.5.3+ (build 20250404). No habilita ni entrena modelos predictivos.

## Licencia

GPL-3.0-or-later. Consulta `LICENSE`.
