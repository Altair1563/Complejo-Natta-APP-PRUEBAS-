<?php
/**
 * Política de Privacidad (pública, sin sesión).
 * Versión vigente y hash en pie desde la base de datos.
 */
require_once __DIR__ . '/config/session.php';
secure_session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

$policy_meta = null;
$policy_db_error = false;

if (!defined('_ACCESS')) {
    define('_ACCESS', true);
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/privacy_policy.php';

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    $policy_meta = privacy_policy_get_active($conn);
    $conn->close();
} catch (Throwable $e) {
    error_log('politica-privacidad.php: ' . $e->getMessage());
    $policy_db_error = true;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$version_label = $policy_meta ? htmlspecialchars($policy_meta['policy_version'], ENT_QUOTES, 'UTF-8') : '—';
$hash_label = $policy_meta ? htmlspecialchars($policy_meta['policy_hash'], ENT_QUOTES, 'UTF-8') : '—';
$effective_label = $policy_meta && !empty($policy_meta['effective_at'])
    ? htmlspecialchars($policy_meta['effective_at'], ENT_QUOTES, 'UTF-8')
    : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidad — Milagrosa App</title>
    <link rel="stylesheet" href="./css/main.css">
    <style>
        .privacy-page {
            margin: 0;
            min-height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-family: Roboto, "Helvetica Neue", Helvetica, Arial, sans-serif;
            background: linear-gradient(165deg, #eceff1 0%, #cfd8dc 35%, #eceff1 100%);
        }
        .privacy-header {
            flex-shrink: 0;
            padding: 14px 20px;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
            z-index: 2;
        }
        .privacy-header a {
            color: #c62828;
            font-weight: 500;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .privacy-header a:hover { text-decoration: underline; }
        .privacy-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 16px 32px;
            -webkit-overflow-scrolling: touch;
            scrollbar-gutter: stable;
        }
        .privacy-scroll::-webkit-scrollbar {
            width: 11px;
        }
        .privacy-scroll::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.06);
            border-radius: 10px;
            margin: 8px 0;
        }
        .privacy-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #e57373 0%, #c62828 100%);
            border-radius: 10px;
            border: 2px solid rgba(255, 255, 255, 0.35);
        }
        .privacy-scroll::-webkit-scrollbar-thumb:hover {
            background: #b71c1c;
        }
        .privacy-card {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border-radius: 14px;
            padding: 28px 28px 36px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08), 0 1px 0 rgba(255, 255, 255, 0.8) inset;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        @supports (scrollbar-width: thin) {
            .privacy-scroll {
                scrollbar-width: thin;
                scrollbar-color: #c62828 rgba(0, 0, 0, 0.08);
            }
        }
        .privacy-card h1 {
            font-size: 1.85rem;
            margin: 0 0 8px;
            color: #1a237e;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .privacy-card h2 {
            font-size: 1.12rem;
            margin: 1.65rem 0 0.5rem;
            color: #37474f;
            padding-bottom: 6px;
            border-bottom: 2px solid #eceff1;
        }
        .privacy-card p,
        .privacy-card li {
            color: #455a64;
            line-height: 1.6;
            font-size: 0.96rem;
        }
        .privacy-card ul { padding-left: 1.35rem; margin: 0.5rem 0 0; }
        .privacy-card li { margin-bottom: 0.35rem; }
        .privacy-meta {
            font-size: 0.9rem;
            color: #546e7a;
            margin: 0 0 1.25rem;
            padding: 14px 16px;
            background: #f5f7fa;
            border-radius: 8px;
            border-left: 4px solid #3949ab;
            line-height: 1.5;
        }
        .privacy-footer {
            margin-top: 2.25rem;
            padding: 18px 16px;
            border-radius: 10px;
            background: #fafafa;
            border: 1px solid #e0e0e0;
            font-size: 0.82rem;
            color: #546e7a;
            word-break: break-word;
        }
        .privacy-footer p { margin: 0.35rem 0; font-size: inherit; color: inherit; }
        .privacy-footer strong { color: #37474f; }
        .legal-note {
            background: linear-gradient(90deg, #fff8e1 0%, #fffde7 100%);
            border-left: 4px solid #ffa000;
            padding: 14px 16px;
            margin: 0 0 1.25rem;
            font-size: 0.88rem;
            color: #5d4037;
            border-radius: 0 8px 8px 0;
            line-height: 1.5;
        }
        .privacy-card a {
            color: #c62828;
            font-weight: 500;
        }
        .privacy-card a:hover { text-decoration: underline; }
    </style>
</head>
<body class="privacy-page">
    <header class="privacy-header">
        <a href="/milagrosa-app/index.php">&larr; Volver al inicio de sesión</a>
    </header>
    <main class="privacy-scroll" id="privacy-main">
        <article class="privacy-card">
            <h1>Política de Privacidad</h1>
            <p class="privacy-meta">
                Este documento informa el tratamiento de datos personales en los términos de la <strong>Ley 25.326</strong> de Protección de Datos Personales, su <strong>Decreto reglamentario 1558/2001</strong>, las normas y criterios dictados por la <strong>Dirección Nacional de Protección de Datos Personales (DNPDP)</strong>, incluyendo disposiciones, dictámenes y reglamentaciones aplicables en la materia, así como lo previsto en la normativa sectorial que resulte pertinente.
            </p>

            <h2>1. Identificación completa del responsable del tratamiento (art. 5 Ley 25.326)</h2>
            <p>
                El <strong>responsable</strong> del archivo, banco de datos o tratamiento de datos personales es el titular de la base que define las finalidades y medios del tratamiento. A los fines de esta Política, el responsable es el <strong>Jardín de Infantes La Milagrosa</strong> (en adelante, “Jardín de Infantes La Milagrosa” o “el Establecimiento”), perteneciente al <strong>Obispado de Lomas de Zamora</strong>, con las siguientes salas/unidades educativas:
            </p>
            <ul>
                <li>Sala Celeste Inicial</li>
                <li>Sala Roja Inicial</li>
                <li>Sala Verde Inicial</li>
                <li>Nuevos Inicial</li>
            </ul>
            <p>Datos de identificación del responsable:</p>
            <ul>
                <li><strong>Razón social / denominación legal:</strong> Jardín de Infantes La Milagrosa.</li>
                <li><strong>Nombre comercial / denominación de uso:</strong> Jardín de Infantes La Milagrosa.</li>
                <li><strong>Afiliación institucional:</strong> perteneciente al Obispado de Lomas de Zamora.</li>
                <li><strong>C.U.I.T.:</strong> Completar.</li>
                <li><strong>Domicilio real y constituido</strong> (para notificaciones y ejercicio de derechos): Completar.</li>
                <li><strong>Correo electrónico institucional y para reclamos en materia de protección de datos personales:</strong> Completar.</li>
                <li><strong>Sitio web:</strong> Completar.</li>
            </ul>

            <h2>2. Bases jurídicas del tratamiento y consentimiento (arts. 5, 6 y 11 Ley 25.326)</h2>
            <p>
                El tratamiento de datos del alumno y de su familia se sustenta, según el caso, en las siguientes <strong>bases jurídicas</strong>, sin perjuicio de la información específica que deba brindarse en cada supuesto:
            </p>
            <ul>
                <li><strong>a) Ejecución de la relación educativa y administrativa:</strong> gestión administrativa y comunicaciones institucionales inherentes al servicio, así como la administración de contratos y pagos en el marco del vínculo con el Establecimiento.</li>
                <li><strong>b) Obligaciones legales:</strong> cumplimiento de normativa contable, fiscal y educativa aplicable al Establecimiento, en particular lo relativo a la conservación de documentación contractual y administrativa.</li>
                <li><strong>c) Consentimiento expreso, libre e informado:</strong> para finalidades que <strong>no</strong> resulten estrictamente necesarias de las anteriores —por ejemplo, uso de imagen en actividades extracurriculares, encuestas de satisfacción opcionales u otros tratamientos accesorios que la ley o la DNPDP exijan como tales.</li>
            </ul>
            <p>
                Cuando el consentimiento sea la base aplicable, será <strong>inequívoco</strong> respecto de datos no obligatorios para la prestación del servicio educativo básico, y podrá constar por medios digitales idóneos dejando constancia en los sistemas del Establecimiento. Dicho consentimiento podrá ser <strong>revocado en cualquier momento</strong>, sin efectos retroactivos, sin perjuicio del tratamiento previo lícito y de las obligaciones legales de conservación que correspondan.
            </p>
            <p>
                Los <strong>alumnos mayores de edad</strong> podrán ejercer derechos y prestar consentimientos que la ley atribuya a los titulares, en los términos de la normativa vigente; en el caso de <strong>menores de edad</strong>, se estará a lo previsto en la sección 8 de esta Política.
            </p>

            <h2>3. Categorías de datos que se tratan</h2>
            <p>El Establecimiento trata exclusivamente las siguientes categorías de datos personales, limitadas a las finalidades administrativas y contractuales:</p>
            <ul>
                <li>Datos identificativos de alumnos/as y familias (nombre, apellido, DNI, número de legajo, número de familia, y los que resulten necesarios para la individualización de las partes en los contratos digitales).</li>
                <li>Datos de contacto (correo electrónico de padres, madres o tutores registrados en legajo, teléfono y demás datos de contacto institucional necesarios para las comunicaciones).</li>
                <li>Datos administrativos y contractuales necesarios para la gestión de la relación con las familias (información de cuotas, pagos, planes de pago, aceptación de contratos digitales, y demás documentación vinculada a la administración del servicio educativo).</li>
                <li>Datos derivados del uso de esta aplicación web (dirección IP, agente de usuario, registros de auditoría en eventos de seguridad, inicio de sesión y aceptación de documentos o políticas).</li>
            </ul>
            <p><strong>No se tratan en este sistema datos académicos (asistencia, evaluaciones, calificaciones, informes pedagógicos) ni datos de salud o condición especial.</strong> Dichos datos, en caso de ser recolectados por otros medios administrativos o pedagógicos del Establecimiento, se rigen por políticas internas específicas y no forman parte de la presente Política aplicable a esta aplicación web.</p>

            <h2>4. Finalidades concretas, correo electrónico y contratos digitales</h2>
            <p>
                Los datos se utilizan exclusivamente para finalidades vinculadas a la administración de la relación con las familias y a la gestión contractual: comunicaciones institucionales de carácter administrativo, gestión de cuotas y pagos, notificaciones sobre la relación contractual, y cumplimiento de obligaciones legales, siempre en coherencia con las bases jurídicas indicadas en el apartado 2.
            </p>
            <p>
                Las direcciones de correo registradas se emplean para envíos relacionados con la cuenta, la recuperación de acceso, la gestión de contratos digitales y notificaciones administrativas (confirmaciones de aceptación de documentos, recordatorios de pagos, entre otras). No se utilizarán para finalidades incompatibles con el vínculo educativo sin la correspondiente base legal o consentimiento cuando la ley lo exija.
            </p>
            <p>
                Cuando utilice el <strong>módulo de contratos digitales</strong> de esta aplicación, las aceptaciones podrán registrarse con fecha y hora, versión del documento, huella de integridad cuando corresponda, dirección IP y datos identificativos del declarante, a efectos de acreditar el consentimiento informado y cumplir fines administrativos, probatorios y legales.
            </p>

            <h2>5. Plazos de conservación (art. 8 Ley 25.326)</h2>
            <p>
                Los datos se conservan durante el tiempo necesario para cumplir las finalidades que los justifican y las obligaciones legales aplicables, aplicando criterios objetivos y la normativa sectorial. A título orientativo, el Establecimiento utiliza los siguientes <strong>plazos máximos</strong> por categoría, sin perjuicio de ajustes que imponga la ley local o nacional vigente:
            </p>
            <ul>
                <li><strong>Documentación administrativa y contractual (incluyendo contratos digitales y registros de pagos):</strong> mientras dure la relación educativa y hasta <strong>10 años</strong> posteriores a su finalización, a efectos de cumplir con obligaciones legales (fiscales, contables, educativas) y de acreditar la manifestación de voluntad de las partes.</li>
                <li><strong>Direcciones IP y logs de auditoría de la aplicación:</strong> hasta <strong>1 año</strong> desde su generación, salvo que deban conservarse por un plazo mayor por requerimiento judicial, administrativo o probatorio fundado.</li>
                <li><strong>Consentimientos (cuando sean la base del tratamiento):</strong> mientras dure la relación educativa y hasta <strong>3 años</strong> posteriores a su finalización, a efectos de acreditar la voluntad del titular.</li>
            </ul>
            <p>
                Finalizados los plazos aplicables, los datos serán <strong>suprimidos o anonimizados</strong> de manera permanente. Cuando la supresión no sea posible por obligación legal (archivo histórico, procesos judiciales, etc.), procederá el <strong>bloqueo</strong> de los datos para cualquier tratamiento no permitido, conforme al art. 8 de la Ley 25.326.
            </p>

            <h2>6. Medidas de seguridad (art. 9 Ley 25.326; Decreto 1558/2001; Resolución DNPDP 11/2006)</h2>
            <p>
                El Establecimiento adopta un nivel de seguridad acorde a la naturaleza de los datos tratados y a los riesgos involucrados, implementando en particular medidas de seguridad de <strong>nivel medio</strong> previstas en la <strong>Resolución DNPDP 11/2006</strong> y complementadas por el <strong>Decreto 1558/2001</strong>, entre las que se incluyen, sin carácter taxativo:
            </p>
            <ul>
                <li>Control de accesos con <strong>autenticación personal e individualizada</strong> (usuario y contraseña, con políticas de robustez) a los sistemas que tratan datos personales.</li>
                <li>Registro de accesos y <strong>auditorías periódicas</strong> razonables sobre el uso de la plataforma.</li>
                <li>Uso de <strong>conexiones seguras mediante protocolos TLS vigentes</strong> para proteger la transmisión de información entre el usuario y la plataforma.</li>
                <li>Protección de bases de datos y archivos con datos personales, con medidas de confidencialidad e integridad acordes al riesgo, incluyendo controles de acceso por rol, respaldo periódico cifrado y monitoreo de accesos sobre los sistemas que alojan datos personales.</li>
                <li><strong>Copias de respaldo</strong> almacenadas en ubicaciones seguras, con controles de acceso y recuperación acotados al personal autorizado.</li>
                <li><strong>Procedimiento interno de respuesta ante incidentes de seguridad</strong>: ante una violación de la seguridad de los datos personales que genere riesgo relevante para los titulares, el Establecimiento adoptará las medidas técnicas, organizativas y administrativas necesarias para contener el incidente, evaluar su alcance y cumplir con las obligaciones de información que resulten exigibles conforme a la normativa vigente y las directrices de la autoridad competente.<br><br>
                Cuando corresponda legalmente, se informará a la autoridad de aplicación y/o a los titulares afectados dentro de los plazos y condiciones previstos por la normativa aplicable.<br><br>
                Las actuaciones realizadas serán documentadas internamente y el Establecimiento colaborará con las autoridades competentes en la investigación y mitigación de sus efectos.</li>
            </ul>
            <p><strong>Proveedores tecnológicos:</strong> El Establecimiento podrá utilizar proveedores tecnológicos para el alojamiento, respaldo, mantenimiento o soporte de los sistemas informáticos vinculados a esta aplicación. Dichos proveedores actuarán exclusivamente conforme a las instrucciones del Establecimiento y deberán adoptar medidas de seguridad razonables y adecuadas para la protección de los datos personales tratados.</p>
            <p>
                Ningún sistema es absolutamente invulnerable; ante un incidente, el Establecimiento actuará con diligencia, documentará las medidas adoptadas y colaborará con las autoridades competentes.
            </p>

            <h2>7. Cookies y tecnologías similares (criterios DNPDP)</h2>
            <p>
                Esta aplicación utiliza, en la actualidad, <strong>cookies de sesión estrictamente necesarias</strong> para el funcionamiento técnico del inicio de sesión y la autenticación del usuario. Son temporales y se eliminan al cerrar el navegador o al cerrar sesión, según la configuración del equipo.
            </p>
            <p>
                En línea con los criterios de la DNPDP en materia de cookies y tecnologías similares (entre otros, <strong>Dictamen DNPDP 1/2018</strong>), si en el futuro se implementaran <strong>cookies de análisis, preferencias o personalización</strong> no estrictamente indispensables, el Establecimiento solicitará el <strong>consentimiento explícito</strong> del usuario mediante un aviso claro (por ejemplo, banner o cuadro de diálogo) antes de su instalación, salvo que resulte aplicable otra base jurídica.
            </p>
            <p>
                El titular puede configurar su navegador para bloquear o eliminar cookies; ello podría afectar la funcionalidad de la plataforma, en particular el inicio de sesión.
            </p>

            <h2>8. Datos de niñas, niños y adolescentes (arts. 3 y 6 Ley 25.326; Ley 26.061)</h2>
            <p>
                Para los alumnos <strong>menores de 18 años</strong>, el ejercicio de los derechos de acceso, rectificación, actualización y supresión (ARCO) y la prestación del consentimiento para tratamientos que lo requieran recae, en principio, en sus <strong>padres, madres o tutores legales</strong>, quienes deberán acreditar la representación cuando el Establecimiento lo solicite razonablemente, en concordancia con la Ley 26.061 de Protección Integral de los Derechos de las Niñas, Niños y Adolescentes.
            </p>
            <p>
                El Establecimiento no recabará datos personales de menores sin la intervención de sus representantes legales, salvo aquellos datos mínimos que el menor aporte en el marco estrictamente administrativo o con fines de identificación en la plataforma, siempre en conformidad con la ley y las políticas internas del colegio, priorizando el interés superior del niño.
            </p>

            <h2>9. Reclamos ante la DNPDP y contacto institucional</h2>
            <p>
                <strong>Dirección Nacional de Protección de Datos Personales (DNPDP)</strong>, organismo descentralizado actuante en el ámbito de la Agencia de Acceso a la Información Pública:
            </p>
            <ul>
                <li><strong>Domicilio:</strong> Tucumán 358, Ciudad Autónoma de Buenos Aires.</li>
                <li><strong>Teléfono gratuito:</strong> 0800-333-0983.</li>
                <li><strong>Sitio web:</strong> <a href="https://www.argentina.gob.ar/dnpdp" target="_blank" rel="noopener noreferrer">www.argentina.gob.ar/dnpdp</a></li>
            </ul>
            <p>
                Para consultas generales sobre esta Política o para iniciar un trámite de ARCO ante el Establecimiento, puede utilizarse el correo institucional indicado en el apartado 1 y los canales presenciales o telefónicos de secretaría.
            </p>

            <div class="privacy-footer">
                <?php if ($policy_db_error): ?>
                    <p><em>No se pudo obtener la versión registrada en base de datos en este momento.</em></p>
                <?php else: ?>
                    <p><strong>Versión publicada (registro):</strong> <?php echo htmlspecialchars($version_label); ?></p>
                    <p><strong>Vigencia desde (registro):</strong> <?php echo htmlspecialchars($effective_label); ?></p>
                    <p><strong>Huella SHA-256 (registro):</strong> <?php echo htmlspecialchars($hash_label); ?></p>
                <?php endif; ?>
            </div>
        </article>
    </main>
</body>
</html>
