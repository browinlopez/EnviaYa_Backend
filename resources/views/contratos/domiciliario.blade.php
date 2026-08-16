{{--
  Acuerdo de vinculación del domiciliario.

  El texto es el mismo del documento en papel (ACUERDO DE VINCULACIÓN
  DOMICILIARIO.docx): acá solo se rellenan los espacios que en el original van
  en blanco y se estampa la firma capturada en el panel.

  Se pinta con dompdf, que soporta un subconjunto de CSS: nada de flexbox ni
  grid, y los estilos van en línea o en un <style> simple.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acuerdo de vinculación · {{ $trabajador['nombre'] }}</title>
    <style>
        @page { margin: 2.2cm 2cm 2.4cm 2cm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            line-height: 1.45;
            color: #1a1a1a;
        }

        h1 {
            font-size: 12pt;
            text-align: center;
            line-height: 1.35;
            margin: 0 0 18px;
        }

        h2 {
            font-size: 10pt;
            margin: 16px 0 6px;
        }

        p { margin: 0 0 8px; text-align: justify; }

        ul { margin: 0 0 8px; padding-left: 16px; }
        li { margin-bottom: 3px; text-align: justify; }

        .dato { font-weight: bold; }

        /* Espacio en blanco del documento en papel que no se pudo completar. */
        .vacio {
            display: inline-block;
            min-width: 150px;
            border-bottom: 1px solid #555;
        }

        /* Las dos firmas van juntas y en su propia hoja: repartidas entre dos
           páginas, la del trabajador quedaba suelta sin el encabezado. */
        .firmas {
            page-break-before: always;
            page-break-inside: avoid;
        }

        .firma-bloque {
            width: 100%;
            margin-bottom: 26px;
            page-break-inside: avoid;
        }

        .firma-titulo {
            font-weight: bold;
            font-size: 9.5pt;
            margin-bottom: 10px;
        }

        .firma-imagen {
            height: 70px;
            margin-bottom: 2px;
        }

        .firma-linea {
            border-top: 1px solid #333;
            width: 260px;
            padding-top: 3px;
            font-size: 9pt;
        }

        .firma-dato { font-size: 9.5pt; margin: 0 0 3px; }

        .pie {
            margin-top: 22px;
            padding-top: 8px;
            border-top: 1px solid #ddd;
            font-size: 7.5pt;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>

<h1>ACUERDO DE VINCULACIÓN COMO TRABAJADOR DIGITAL INDEPENDIENTE DE SERVICIOS DE
    REPARTO MEDIANTE PLATAFORMA DIGITAL</h1>

<p>Entre los suscritos a saber:</p>

<p><span class="dato">{{ $empresa['nombre'] }}</span>, sociedad comercial legalmente
    constituida conforme a las leyes de la República de Colombia, identificada con NIT
    @if ($empresa['nit'])
        <span class="dato">{{ $empresa['nit'] }}</span>,
    @else
        <span class="vacio"></span>,
    @endif
    propietaria y operadora de la plataforma tecnológica {{ $empresa['plataforma'] }},
    representada legalmente por quien suscribe el presente documento, quien en adelante se
    denominará <span class="dato">LA PLATAFORMA</span>.</p>

<p>Y de otra parte,</p>

<p><span class="dato">{{ $trabajador['nombre'] }}</span>, mayor de edad, identificado con
    cédula de ciudadanía No.
    @if ($trabajador['documento'])
        <span class="dato">{{ $trabajador['documento'] }}</span>,
    @else
        <span class="vacio"></span>,
    @endif
    quien actúa en nombre propio y manifiesta tener capacidad legal para contratar, quien
    en adelante se denominará <span class="dato">EL TRABAJADOR DIGITAL INDEPENDIENTE</span>.</p>

<p>Las partes acuerdan celebrar el presente Acuerdo de Vinculación como Trabajador Digital
    Independiente de Servicios de Reparto mediante Plataforma Digital, el cual se regirá
    por las siguientes cláusulas:</p>

<h2>CLÁUSULA PRIMERA. OBJETO</h2>
<p>El presente acuerdo tiene por objeto regular la vinculación de EL TRABAJADOR DIGITAL
    INDEPENDIENTE con LA PLATAFORMA, para que, utilizando sus propios medios, herramientas
    y vehículo, preste servicios de reparto de productos solicitados por los usuarios de la
    plataforma tecnológica {{ $empresa['plataforma'] }}, conforme a las condiciones
    establecidas en este documento.</p>
<p>La actividad desarrollada consistirá exclusivamente en aceptar voluntariamente
    solicitudes de reparto disponibles en la plataforma, recoger los productos en los
    comercios aliados y entregarlos al usuario final.</p>

<h2>CLÁUSULA SEGUNDA. MARCO LEGAL</h2>
<p>La presente vinculación se celebra de conformidad con lo dispuesto en los artículos 25,
    26 y 27 de la Ley 2466 de 2025, así como las demás normas que la modifiquen, adicionen
    o reglamenten.</p>
<p>Las partes reconocen que dicha ley autoriza la vinculación de trabajadores digitales
    independientes mediante plataformas digitales de reparto, conservando su autonomía e
    independencia y sin que ello implique, por sí mismo, la existencia de una relación
    laboral subordinada.</p>
<p>LA PLATAFORMA cumplirá las obligaciones que la legislación vigente le imponga respecto
    de los trabajadores digitales independientes, especialmente en materia de aportes al
    Sistema Integral de Seguridad Social y cobertura del Sistema General de Riesgos
    Laborales, conforme a la reglamentación aplicable.</p>

<h2>CLÁUSULA TERCERA. NATURALEZA DE LA VINCULACIÓN</h2>
<p>Las partes manifiestan expresamente que la presente vinculación tiene naturaleza
    civil/comercial, bajo la modalidad de trabajador digital independiente prevista en la
    Ley 2466 de 2025.</p>
<p>En consecuencia:</p>
<ul>
    <li>EL TRABAJADOR DIGITAL INDEPENDIENTE conserva plena autonomía para decidir cuándo
        conectarse a la plataforma, cuándo aceptar o rechazar solicitudes de reparto y
        cuándo finalizar su disponibilidad.</li>
    <li>No existe subordinación jurídica permanente.</li>
    <li>No existe obligación de cumplir horarios.</li>
    <li>No existe exclusividad.</li>
    <li>EL TRABAJADOR DIGITAL INDEPENDIENTE podrá prestar servicios para otras plataformas
        digitales o desarrollar cualquier otra actividad económica lícita.</li>
    <li>La utilización de herramientas tecnológicas para la asignación de pedidos,
        geolocalización, seguimiento del servicio, calificaciones o control de calidad no
        constituye subordinación laboral, sino mecanismos propios del funcionamiento de una
        plataforma digital.</li>
</ul>

<h2>CLÁUSULA CUARTA. DURACIÓN</h2>
<p>El presente acuerdo tendrá una duración indefinida, iniciando a partir de su aceptación
    por ambas partes.</p>
<p>Cualquiera de las partes podrá darlo por terminado en cualquier momento, de conformidad
    con las causales previstas en este acuerdo.</p>

<h2>CLÁUSULA QUINTA. FORMA DE PRESTACIÓN DEL SERVICIO</h2>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE prestará los servicios utilizando sus propios medios
    de transporte, teléfono móvil, conexión a internet y demás herramientas necesarias para
    desarrollar la actividad.</p>
<p>Será responsabilidad del trabajador mantener dichos elementos en adecuado estado de
    funcionamiento.</p>
<p>La aceptación de cada pedido será voluntaria y corresponderá exclusivamente al trabajador
    decidir si lo acepta o no, conforme a su disponibilidad.</p>

<h2>CLÁUSULA SEXTA. REMUNERACIÓN</h2>
<p>Por cada servicio efectivamente realizado y finalizado, EL TRABAJADOR DIGITAL
    INDEPENDIENTE recibirá la remuneración correspondiente de acuerdo con las tarifas
    establecidas por LA PLATAFORMA y previamente informadas mediante la aplicación.</p>
<p>Los pagos se efectuarán mediante cortes periódicos conforme al calendario definido por
    LA PLATAFORMA y comunicado oportunamente al trabajador.</p>
<p>LA PLATAFORMA podrá establecer incentivos, bonificaciones o campañas promocionales, los
    cuales serán de carácter temporal y no constituirán salario, prestación social ni
    derecho adquirido.</p>

<h2>CLÁUSULA SÉPTIMA. SEGURIDAD SOCIAL Y RIESGOS LABORALES</h2>
<p>LA PLATAFORMA y EL TRABAJADOR DIGITAL INDEPENDIENTE darán cumplimiento a las obligaciones
    establecidas en los artículos 25, 26 y 27 de la Ley 2466 de 2025 y demás normas que la
    reglamenten, modifiquen o sustituyan.</p>
<p>Cuando la normatividad vigente así lo disponga, LA PLATAFORMA concurrirá al pago de los
    aportes al Sistema General de Seguridad Social en Salud y Pensión en la proporción
    establecida por la ley para los trabajadores digitales independientes y asumirá el pago
    de la cotización al Sistema General de Riesgos Laborales (ARL) cuando corresponda.</p>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE se obliga a suministrar la información y
    documentación requerida para el cumplimiento de dichas obligaciones.</p>

<h2>CLÁUSULA OCTAVA. OBLIGACIONES DEL TRABAJADOR DIGITAL INDEPENDIENTE</h2>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE se obliga a:</p>
<ul>
    <li>Prestar el servicio con diligencia, respeto y buena fe.</li>
    <li>Cumplir las normas de tránsito y seguridad vial.</li>
    <li>Mantener vigente su licencia de conducción cuando aplique.</li>
    <li>Utilizar casco y demás elementos de protección personal exigidos por la ley.</li>
    <li>Mantener en buen estado el vehículo utilizado para la prestación del servicio.</li>
    <li>Conservar una adecuada presentación personal.</li>
    <li>Tratar con respeto a usuarios, comercios aliados, residentes, administradores y
        personal de LA PLATAFORMA.</li>
    <li>Cuidar los productos desde su recepción hasta la entrega.</li>
    <li>Entregar los pedidos únicamente al destinatario autorizado.</li>
    <li>Reportar inmediatamente accidentes, pérdidas, novedades o incidentes ocurridos
        durante el servicio.</li>
    <li>Utilizar exclusivamente su cuenta personal dentro de la plataforma.</li>
    <li>Mantener actualizada su información personal y de contacto.</li>
    <li>Cumplir las políticas operativas y de seguridad publicadas por LA PLATAFORMA.</li>
    <li>Proteger la confidencialidad de la información de usuarios y comercios.</li>
    <li>Abstenerse de realizar cualquier conducta que afecte el buen nombre de
        {{ $empresa['plataforma'] }}.</li>
</ul>

<h2>CLÁUSULA NOVENA. PROHIBICIONES</h2>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE no podrá:</p>
<ul>
    <li>Permitir que otra persona utilice su cuenta.</li>
    <li>Prestar el servicio bajo efectos de alcohol o sustancias psicoactivas.</li>
    <li>Manipular, abrir o consumir los productos transportados.</li>
    <li>Solicitar dinero adicional al usuario sin autorización de LA PLATAFORMA.</li>
    <li>Cobrar valores diferentes a los informados en la aplicación.</li>
    <li>Agredir física o verbalmente a usuarios, comercios o residentes.</li>
    <li>Compartir información confidencial obtenida durante la prestación del servicio.</li>
    <li>Presentar documentación falsa.</li>
    <li>Suplantar la identidad de otra persona.</li>
    <li>Utilizar la plataforma para actividades ilícitas.</li>
    <li>Contactar a los usuarios para fines personales o comerciales ajenos al servicio.</li>
    <li>Cometer actos que afecten la imagen o reputación de LA PLATAFORMA.</li>
</ul>
<p>El incumplimiento de cualquiera de estas prohibiciones podrá dar lugar a la suspensión
    temporal o terminación definitiva de la vinculación, sin perjuicio de las acciones
    legales a que haya lugar.</p>

<h2>CLÁUSULA DÉCIMA. OBLIGACIONES DE LA PLATAFORMA</h2>
<p>LA PLATAFORMA se obliga a:</p>
<ul>
    <li>Poner a disposición del trabajador la aplicación tecnológica para la recepción de
        solicitudes de reparto.</li>
    <li>Informar de manera clara las tarifas y condiciones económicas aplicables.</li>
    <li>Realizar los pagos correspondientes dentro de los cortes establecidos.</li>
    <li>Cumplir las obligaciones legales en materia de seguridad social y riesgos laborales
        cuando la ley así lo establezca.</li>
    <li>Brindar canales de atención para reportar incidentes o novedades.</li>
    <li>Proteger la información personal suministrada por el trabajador conforme a la
        legislación sobre protección de datos personales.</li>
    <li>Mantener una póliza de responsabilidad civil cuando corresponda a la operación de
        la plataforma.</li>
</ul>

<h2>CLÁUSULA DÉCIMA PRIMERA. TRATAMIENTO DE DATOS PERSONALES</h2>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE autoriza a LA PLATAFORMA para recolectar, almacenar,
    usar, actualizar y tratar sus datos personales con la finalidad de ejecutar el presente
    acuerdo, gestionar los servicios ofrecidos, cumplir obligaciones legales y mejorar la
    operación de la plataforma, de conformidad con la política de tratamiento de datos
    personales de {{ $empresa['nombre'] }} y la legislación colombiana vigente.</p>

<h2>CLÁUSULA DÉCIMA SEGUNDA. CONFIDENCIALIDAD</h2>
<p>Toda la información técnica, comercial, operativa, financiera o relacionada con los
    usuarios, comercios aliados, estrategias, software y funcionamiento de
    {{ $empresa['plataforma'] }} tendrá carácter confidencial.</p>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE se compromete a no divulgar ni utilizar dicha
    información para fines distintos a la ejecución del presente acuerdo, incluso después
    de su terminación.</p>

<h2>CLÁUSULA DÉCIMA TERCERA. SUSPENSIÓN Y TERMINACIÓN</h2>
<p>LA PLATAFORMA podrá suspender temporalmente el acceso del trabajador cuando existan
    razones fundadas relacionadas con la seguridad de los usuarios, posibles fraudes,
    incumplimiento de las obligaciones aquí pactadas o mientras se adelanta una
    investigación interna.</p>
<p>El presente acuerdo podrá darse por terminado por cualquiera de las partes en cualquier
    momento mediante comunicación escrita.</p>
<p>También procederá la terminación inmediata cuando EL TRABAJADOR DIGITAL INDEPENDIENTE
    incurra en incumplimientos graves, fraude, conductas delictivas, agresiones, utilización
    indebida de la plataforma o cualquier comportamiento que afecte la seguridad de los
    usuarios, los comercios aliados o la reputación de LA PLATAFORMA.</p>

<h2>CLÁUSULA DÉCIMA CUARTA. SOLUCIÓN DE CONTROVERSIAS</h2>
<p>Las diferencias derivadas del presente acuerdo serán resueltas inicialmente mediante
    arreglo directo entre las partes. En caso de no lograrse un acuerdo, estas podrán acudir
    a los mecanismos de conciliación previstos en la legislación colombiana y, de persistir
    la controversia, a la jurisdicción competente.</p>

<h2>CLÁUSULA DÉCIMA QUINTA. ACEPTACIÓN</h2>
<p>Con la firma del presente acuerdo, las partes manifiestan haber leído, comprendido y
    aceptado íntegramente su contenido, declarando que su consentimiento ha sido otorgado
    libremente y sin ningún tipo de presión o vicio.</p>

<p>En constancia se firma en dos ejemplares del mismo tenor en la ciudad de
    <span class="dato">{{ $firma['ciudad'] }}</span>, a los
    <span class="dato">{{ $firma['dia'] }}</span> días del mes de
    <span class="dato">{{ $firma['mes'] }}</span> de
    <span class="dato">{{ $firma['anio'] }}</span>.</p>

<div class="firmas">
    <h2 style="text-align: center; margin-bottom: 18px;">FIRMAS</h2>

    <div class="firma-bloque">
        <p class="firma-titulo">{{ $empresa['nombre'] }} — Representante Legal</p>
        {{-- La empresa firma en el ejemplar impreso: acá va la línea en blanco. --}}
        <div style="height: 70px;"></div>
        <div class="firma-linea">Firma</div>
        <p class="firma-dato" style="margin-top: 8px;">
            Nombre:
            {{ $empresa['representante'] ?: '__________________________' }}
        </p>
        <p class="firma-dato">
            C.C.: {{ $empresa['representante_cc'] ?: '____________________________' }}
        </p>
    </div>

    <div class="firma-bloque">
        <p class="firma-titulo">EL TRABAJADOR DIGITAL INDEPENDIENTE</p>
        <img class="firma-imagen" src="{{ $firma['imagen'] }}" alt="Firma">
        <div class="firma-linea">Firma</div>
        <p class="firma-dato" style="margin-top: 8px;">
            Nombre: {{ $trabajador['nombre'] }}
        </p>
        <p class="firma-dato">
            C.C.: {{ $trabajador['documento'] ?: '____________________________' }}
        </p>
    </div>
</div>

{{-- Trazabilidad: sin esto, un PDF firmado en pantalla no se distingue de uno
     que alguien armó por su cuenta. --}}
<div class="pie">
    Documento generado electrónicamente por {{ $empresa['plataforma'] }} el
    {{ $firma['generado'] }}. Firma capturada en el panel de administración por
    {{ $firma['capturado_por'] }}. Identificador de verificación:
    {{ $firma['huella'] }}.
</div>

</body>
</html>
