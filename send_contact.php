<?php
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Requête invalide."]);
    exit;
}

// Sécurisation
$nom     = trim($_POST['nom'] ?? '');
$email   = trim($_POST['email'] ?? '');
$sujet   = trim($_POST['sujet'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!$nom || !$email || !$sujet || !$message) {
    echo json_encode(["success" => false, "message" => "Tous les champs sont obligatoires."]);
    exit;
}

// ================= EMAIL =================
$to = "oldegaaxeus@gmail.com"; // ⚠️ À MODIFIER
$subject = "Nouveau message - Formulaire de contact";

$body = "Nom : $nom\n";
$body .= "Email : $email\n";
$body .= "Sujet : $sujet\n\n";
$body .= "Message :\n$message\n";

$headers  = "From: $email\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8";

if (!mail($to, $subject, $body, $headers)) {
    echo json_encode(["success" => false, "message" => "Impossible d'envoyer l'email."]);
    exit;
}

// ================= WHATSAPP =================
$numeroWhatsApp = "50938504257"; // ⚠️ Votre numéro sans +
$texte = "Nom: $nom\nEmail: $email\nSujet: $sujet\nMessage: $message";
$texte = urlencode($texte);

$whatsappUrl = "https://wa.me/$numeroWhatsApp?text=$texte";

// ================= RÉPONSE =================
echo json_encode([
    "success" => true,
    "whatsapp_url" => $whatsappUrl
]);
