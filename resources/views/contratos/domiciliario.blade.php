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
    representada legalmente por
    @if ($empresa['representante'])
        <span class="dato">{{ $empresa['representante'] }}</span>,
        mayor de edad, identificado con cédula de ciudadanía No.
        @if ($empresa['representante_cc'])
            <span class="dato">{{ $empresa['representante_cc'] }}</span>,
        @else
            <span class="vacio"></span>,
        @endif
    @else
        quien suscribe el presente documento,
    @endif
    quien en adelante se denominará <span class="dato">LA PLATAFORMA</span>.</p>

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
    <li>Las solicitudes de reparto disponibles se le ofrecen por pertenecer a los
        comercios aliados a los que está vinculado, y es él quien decide cuáles toma.
        <strong>La plataforma no utiliza su ubicación para ofrecerle ni para asignarle
        pedidos</strong>; la geolocalización opera únicamente en los términos de la
        CLÁUSULA DÉCIMA SEGUNDA.</li>
    <li>La utilización de herramientas tecnológicas para el seguimiento del servicio en
        curso, las calificaciones o el control de calidad no constituye subordinación
        laboral, sino mecanismos propios del funcionamiento de una plataforma digital.</li>
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

<h2>CLÁUSULA DÉCIMA SEGUNDA. GEOLOCALIZACIÓN DURANTE EL TURNO DE REPARTO</h2>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE autoriza expresamente a LA PLATAFORMA para
    recolectar y tratar los datos de ubicación del dispositivo móvil en el que haya
    iniciado sesión, <strong>únicamente durante su turno de reparto</strong>, entendido
    como el lapso que transcurre desde que acepta una solicitud de reparto hasta que
    entrega el último pedido que tenga asignado.</p>
<p>La finalidad de este tratamiento es exclusivamente operativa: permitir que el usuario
    comprador y el comercio aliado conozcan el avance de la entrega en curso, estimar los
    tiempos de llegada y atender incidencias de seguridad durante el reparto.</p>
<p>LA PLATAFORMA declara y EL TRABAJADOR DIGITAL INDEPENDIENTE reconoce que:</p>
<ul>
    <li><strong>Fuera del turno de reparto no se recolecta la ubicación.</strong> Cuando no
        tiene pedidos asignados, la aplicación deja de registrar su posición.</li>
    <li>La recolección puede ocurrir con la aplicación en segundo plano mientras dure el
        turno, de manera que el seguimiento de la entrega no se interrumpa cuando bloquee
        el teléfono. El dispositivo mostrará un aviso permanente mientras esto ocurra.</li>
    <li>EL TRABAJADOR DIGITAL INDEPENDIENTE puede revocar este permiso en cualquier momento
        desde los ajustes de su dispositivo o desde la aplicación. Hacerlo no termina el
        presente acuerdo, pero puede impedir que se le asignen repartos mientras el permiso
        esté revocado, por ser la ubicación un dato necesario para prestar el servicio.</li>
    <li>Los datos de ubicación no se utilizarán para evaluar la conducta del trabajador
        fuera del turno, ni se cederán a terceros distintos del comercio aliado y del
        usuario comprador del pedido en curso, salvo requerimiento de autoridad
        competente.</li>
</ul>
<p><strong>Conservación.</strong> Los datos de ubicación se conservan durante
    <strong>noventa (90) días calendario</strong> contados desde su registro, plazo
    destinado a atender reclamaciones sobre la entrega e incidentes de seguridad ocurridos
    durante ella. Cumplido ese término se suprimen de forma automática y definitiva, sin
    que sea necesaria solicitud alguna del titular.</p>
<p>Este tratamiento se realiza conforme a la Ley 1581 de 2012 y sus decretos
    reglamentarios, y a la política de tratamiento de datos personales de LA PLATAFORMA.
    EL TRABAJADOR DIGITAL INDEPENDIENTE conserva los derechos de conocer, actualizar,
    rectificar y solicitar la supresión de sus datos en los términos de dicha ley.</p>

<h2>CLÁUSULA DÉCIMA TERCERA. CONFIDENCIALIDAD</h2>
<p>Toda la información técnica, comercial, operativa, financiera o relacionada con los
    usuarios, comercios aliados, estrategias, software y funcionamiento de
    {{ $empresa['plataforma'] }} tendrá carácter confidencial.</p>
<p>EL TRABAJADOR DIGITAL INDEPENDIENTE se compromete a no divulgar ni utilizar dicha
    información para fines distintos a la ejecución del presente acuerdo, incluso después
    de su terminación.</p>

<h2>CLÁUSULA DÉCIMA CUARTA. SUSPENSIÓN Y TERMINACIÓN</h2>

<p><strong>1. Causales de suspensión.</strong> LA PLATAFORMA podrá suspender temporalmente
    el acceso de EL TRABAJADOR DIGITAL INDEPENDIENTE cuando existan razones fundadas y
    concretas relacionadas con la seguridad de los usuarios, posibles fraudes o
    incumplimiento de las obligaciones aquí pactadas, mientras se adelanta la verificación
    correspondiente.</p>

<p><strong>2. Aviso.</strong> Toda suspensión será comunicada a EL TRABAJADOR DIGITAL
    INDEPENDIENTE a más tardar <strong>dentro del día hábil siguiente</strong>, por la
    aplicación y por los canales de notificación señalados en este acuerdo, indicando
    <strong>la causa concreta</strong> que la motiva y la duración prevista. Un aviso que
    se limite a informar la suspensión sin expresar su causa no satisface esta
    obligación.</p>

<p><strong>3. Plazo máximo.</strong> La suspensión no podrá exceder de <strong>diez (10)
    días hábiles</strong>. Vencido ese término sin decisión motivada, el acceso se
    restablece automáticamente.</p>

<p><strong>4. Derecho a ser oído.</strong> EL TRABAJADOR DIGITAL INDEPENDIENTE podrá
    presentar sus explicaciones y las pruebas que considere pertinentes dentro de los cinco
    (5) días hábiles siguientes al aviso, por los mismos canales. LA PLATAFORMA está
    obligada a valorarlas antes de decidir.</p>

<p><strong>5. Revisión humana de las decisiones automatizadas.</strong> Cuando la
    suspensión, la restricción del acceso, la terminación del acuerdo o la retención de un
    pago se hayan originado total o parcialmente en un sistema automatizado, EL TRABAJADOR
    DIGITAL INDEPENDIENTE tiene derecho a solicitar que <strong>una persona</strong>
    revise la decisión. La revisión se resolverá dentro de los cinco (5) días hábiles
    siguientes a la solicitud, mediante <strong>decisión motivada</strong> que exprese los
    hechos y las razones en que se funda. Ninguna decisión que afecte su acceso a la
    plataforma o sus pagos quedará en firme sin que esta revisión esté disponible.</p>

<p><strong>6. Terminación.</strong> El presente acuerdo podrá darse por terminado por
    cualquiera de las partes en cualquier momento mediante comunicación escrita, sin que
    ello genere indemnización a cargo de ninguna de ellas.</p>

<p><strong>7. Terminación inmediata.</strong> Procederá cuando EL TRABAJADOR DIGITAL
    INDEPENDIENTE incurra en incumplimientos graves, fraude, conductas delictivas,
    agresiones o utilización indebida de la plataforma. La terminación inmediata será
    igualmente <strong>motivada</strong> y estará sujeta a la revisión humana prevista en
    el numeral 5.</p>

<p><strong>8. Pago de lo ya prestado.</strong> La suspensión o la terminación, cualquiera
    sea su causa, <strong>no afectan el derecho de EL TRABAJADOR DIGITAL INDEPENDIENTE a
    recibir el pago de los servicios de reparto que ya haya prestado</strong>. Dichos
    valores se pagarán en el corte inmediatamente siguiente.</p>

<h2>CLÁUSULA DÉCIMA QUINTA. NOTIFICACIONES</h2>
<p>Las comunicaciones escritas que deban cruzarse las partes en desarrollo de este acuerdo
    —incluidas las de suspensión, terminación y revisión— se entenderán válidamente
    surtidas:</p>
<ul>
    <li>A <strong>LA PLATAFORMA</strong>, en el correo electrónico
        <span class="dato">{{ $empresa['correo'] }}</span>.</li>
    <li>A <strong>EL TRABAJADOR DIGITAL INDEPENDIENTE</strong>, en el correo electrónico
        registrado en su cuenta y mediante los avisos de la aplicación. Es su obligación
        mantener ese correo actualizado; las comunicaciones enviadas a la última dirección
        registrada se tendrán por recibidas.</li>
</ul>

<h2>CLÁUSULA DÉCIMA SEXTA. SOLUCIÓN DE CONTROVERSIAS</h2>
<p>Las diferencias derivadas del presente acuerdo serán resueltas inicialmente mediante
    arreglo directo entre las partes. En caso de no lograrse un acuerdo, estas podrán acudir
    a los mecanismos de conciliación previstos en la legislación colombiana y, de persistir
    la controversia, a la jurisdicción competente.</p>

<h2>CLÁUSULA DÉCIMA SÉPTIMA. ACEPTACIÓN</h2>
<p>Con la firma del presente acuerdo, las partes manifiestan haber leído, comprendido y
    aceptado íntegramente su contenido, declarando que su consentimiento ha sido otorgado
    libremente y sin ningún tipo de presión o vicio.</p>

<p>En constancia se suscribe en la ciudad de
    <span class="dato">{{ $firma['ciudad'] }}</span>, a los
    <span class="dato">{{ $firma['dia'] }}</span> días del mes de
    <span class="dato">{{ $firma['mes'] }}</span> de
    <span class="dato">{{ $firma['anio'] }}</span>.</p>

<p><strong>Firma electrónica.</strong> Este acuerdo se suscribe por medios electrónicos y
    las partes le reconocen plena validez y fuerza probatoria, de conformidad con la Ley
    527 de 1999 y el Decreto 2364 de 2012. El mecanismo empleado es confiable y apropiado
    para los fines de este documento, y deja constancia de:</p>
<ul>
    <li>la <strong>identidad</strong> de quien firma, establecida por su sesión autenticada
        en la plataforma, asociada a su cuenta y a su documento de identidad;</li>
    <li>el <strong>trazo de la firma</strong> capturado por la propia persona, que se
        incorpora a este documento;</li>
    <li>la <strong>fecha y hora</strong> de la suscripción y la <strong>dirección IP</strong>
        desde la que se realizó, que figuran al pie;</li>
    <li>un <strong>identificador de verificación</strong> —resumen criptográfico SHA-256—
        que permite comprobar que el documento no ha sido alterado después de firmado, y
        que también figura al pie.</li>
</ul>
<p>LA PLATAFORMA conserva el documento firmado y lo pone a disposición de EL TRABAJADOR
    DIGITAL INDEPENDIENTE desde la aplicación, quien podrá descargarlo en cualquier
    momento.</p>

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
    {{ $firma['generado'] }}. Firma capturada {{ $firma['capturado_por'] }}.
    Identificador de verificación: {{ $firma['huella'] }}.
</div>

</body>
</html>
