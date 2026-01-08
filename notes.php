<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../connexion.php';
include("header.php");

// Vérifier qu'il existe une année scolaire active
$stmt_annee = $conn->prepare("SELECT id FROM annee_scolaire WHERE actif = 1 LIMIT 1");
$stmt_annee->execute();
$result_annee = $stmt_annee->get_result();
$annee_active = $result_annee->fetch_assoc();

if (!$annee_active) {
    echo "<div class='alert alert-danger text-center mt-5'>🚫 Aucune année scolaire active trouvée.</div>";
    include("../footer.php");
    exit();
}

$annee_scolaire_id = $annee_active['id'];

// Charger les trimestres dynamiques
$stmt_trimestres = $conn->prepare("
    SELECT id, libelle 
    FROM trimestre 
    WHERE annee_scolaire_id = ? 
    ORDER BY ordre ASC
");
$stmt_trimestres->bind_param("i", $annee_scolaire_id);
$stmt_trimestres->execute();
$liste_trimestres = $stmt_trimestres->get_result()->fetch_all(MYSQLI_ASSOC);

// Vérifier rôle enseignant
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: login.php');
    exit();
}

$enseignant_id = $_SESSION['enseignant_id'] ?? 0;
$classe_id = $_POST['classe_id'] ?? $_GET['classe_id'] ?? null;
$cours_id = $_POST['cours_id'] ?? $_GET['cours_id'] ?? null;

// Gestion du trimestre
$trimestre = $_POST['trimestre'] ?? $_GET['trimestre'] ?? $_SESSION['trimestre'] ?? null;
if (isset($_POST['trimestre']) || isset($_GET['trimestre'])) {
    $_SESSION['trimestre'] = $trimestre;
}
if (isset($_POST['classe_id']) || isset($_POST['cours_id']) || isset($_GET['classe_id']) || isset($_GET['cours_id'])) {
    unset($_SESSION['trimestre']);
}

// Paramètres de pagination
$eleves_par_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);

$message = "";

// Enregistrement des notes
if (isset($_POST['notes']) && $classe_id && $cours_id && $trimestre) {
    foreach ($_POST['notes'] as $eleve_id => $note) {
        $note = floatval($note);

        // AJOUT: Vérifier avec annee_scolaire_id
        $stmt_check = $conn->prepare("SELECT id FROM notes WHERE eleve_id = ? AND cours_id = ? AND trimestre = ? AND annee_scolaire_id = ?");
        $stmt_check->bind_param("iiii", $eleve_id, $cours_id, $trimestre, $annee_scolaire_id);
        $stmt_check->execute();
        $exists = $stmt_check->get_result()->num_rows > 0;

        if ($exists) {
            $stmt_update = $conn->prepare("
                UPDATE notes SET note = ?, enseignant_id = ?
                WHERE eleve_id = ? AND cours_id = ? AND trimestre = ? AND annee_scolaire_id = ?
            ");
            $stmt_update->bind_param("diiiii", $note, $enseignant_id, $eleve_id, $cours_id, $trimestre, $annee_scolaire_id);
            $stmt_update->execute();
        } else {
            $stmt_insert = $conn->prepare("
                INSERT INTO notes (eleve_id, cours_id, note, trimestre, enseignant_id, annee_scolaire_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert->bind_param("iidiii", $eleve_id, $cours_id, $note, $trimestre, $enseignant_id, $annee_scolaire_id);
            $stmt_insert->execute();
        }
    }
    $message = "✅ Notes enregistrées avec succès.";
}

// Récupération classes
$stmt_classes = $conn->prepare("
    SELECT DISTINCT ecc.classe_id, c.nom AS classe_nom
    FROM enseignant_classe_cours ecc
    JOIN classe c ON ecc.classe_id = c.id
    WHERE ecc.enseignant_id = ?
");
$stmt_classes->bind_param("i", $enseignant_id);
$stmt_classes->execute();
$classes = $stmt_classes->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des notes</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<style>
.note-input {
    max-width: 100px;
    text-align: center;
}
#searchInput {
    max-width: 300px;
}
.container-fluid {
    padding: 15px;
    max-width: 766px;
}
@media (min-width: 768px) {
    .container-fluid {
        margin-left: 300px;
        max-width: 766px;
    }
}
@media (max-width: 767px) {
    .container-fluid {
        margin-left: 0;
        max-width: 500px;
    }
    .table-responsive {
        font-size: 0.875rem;
    }
    .note-input {
        max-width: 80px;
        font-size: 0.875rem;
    }
    .btn-sm-responsive {
        padding: 0.25rem 0.5rem;
        font-size: 0.775rem;
    }
}
.pagination-container {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
}
.page-info {
    display: flex;
    align-items: center;
    margin: 0 15px;
    font-weight: 500;
}
.card {
    margin-bottom: 1rem;
}
</style>

<script>
function filterEleves() {
    let input = document.getElementById("searchInput").value.toLowerCase();
    let rows = document.querySelectorAll("#tableEleves tbody tr");
    let visibleCount = 0;

    rows.forEach(row => {
        let name = row.querySelector(".eleve-name").textContent.toLowerCase();
        if (name.includes(input)) {
            row.style.display = "";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });

    let resultCount = document.getElementById("resultCount");
    if (resultCount) {
        resultCount.textContent = visibleCount + ' élève(s) trouvé(s)';
    }
}

function changePage(page) {
    const url = new URL(window.location.href);
    
    // Préserver tous les paramètres importants
    url.searchParams.set('page', page);
    
    // S'assurer que les paramètres classe_id, cours_id et trimestre sont préservés
    const classeId = '<?= $classe_id ?>';
    const coursId = '<?= $cours_id ?>';
    const trimestreId = '<?= $trimestre ?>';
    
    if (classeId) url.searchParams.set('classe_id', classeId);
    if (coursId) url.searchParams.set('cours_id', coursId);
    if (trimestreId) url.searchParams.set('trimestre', trimestreId);
    
    window.location.href = url.toString();
}

function confirmSave() {
    return confirm('Êtes-vous sûr de vouloir enregistrer les notes ?');
}

// Fonction pour soumettre les formulaires de sélection avec la méthode GET
function submitSelection(form) {
    const url = new URL(window.location.href);
    const formData = new FormData(form);
    
    // Ajouter les paramètres du formulaire à l'URL
    for (let [key, value] of formData.entries()) {
        if (value) {
            url.searchParams.set(key, value);
        }
    }
    
    // Retirer la pagination lors du changement de sélection
    url.searchParams.delete('page');
    
    window.location.href = url.toString();
    return false;
}
</script>

</head>
<body>

<div class="container-fluid mt-3">

<h2 class="mb-4"><i class="bi bi-journal-text me-2"></i>Gestion des notes</h2>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Sélection classe -->
<form method="GET" class="mb-3" onsubmit="return submitSelection(this)">
    <div class="card shadow-sm">
        <div class="card-body">
            <label class="form-label fw-bold">Choisir une classe :</label>
            <select name="classe_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Sélectionner --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['classe_id'] ?>" <?= ($classe_id == $c['classe_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['classe_nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</form>

<?php if ($classe_id): ?>

<?php
$stmt_cours = $conn->prepare("
    SELECT c.id AS cours_id, c.nom AS cours_nom
    FROM cours c
    JOIN enseignant_classe_cours ecc ON c.id = ecc.cours_id
    WHERE ecc.classe_id = ? AND ecc.enseignant_id = ?
");
$stmt_cours->bind_param("ii", $classe_id, $enseignant_id);
$stmt_cours->execute();
$cours = $stmt_cours->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!-- Sélection cours -->
<form method="GET" class="mb-3" onsubmit="return submitSelection(this)">
    <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
    <div class="card shadow-sm">
        <div class="card-body">
            <label class="form-label fw-bold">Choisir un cours :</label>
            <select name="cours_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Sélectionner --</option>
                <?php foreach ($cours as $c): ?>
                    <option value="<?= $c['cours_id'] ?>" <?= ($cours_id == $c['cours_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['cours_nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</form>

<?php endif; ?>

<?php if ($classe_id && $cours_id): ?>

<?php
// Compter le nombre total d'élèves
$stmt_count = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM eleve 
    WHERE classe_id = ?
");
$stmt_count->bind_param("i", $classe_id);
$stmt_count->execute();
$total_eleves = $stmt_count->get_result()->fetch_assoc()['total'];

// Calculer le nombre total de pages
$total_pages = ceil($total_eleves / $eleves_par_page);

// Ajuster la page si nécessaire
if ($page > $total_pages) {
    $page = $total_pages;
}

// Calculer l'offset
$offset = ($page - 1) * $eleves_par_page;

// Récupérer les élèves avec pagination
$stmt_eleves = $conn->prepare("
    SELECT id, nom, prenom, numero_ordre 
    FROM eleve 
    WHERE classe_id = ? 
    ORDER BY
        SUBSTRING_INDEX(numero_ordre, '-', 1) ASC,
        CAST(SUBSTRING_INDEX(numero_ordre, '-', -1) AS UNSIGNED) ASC
    LIMIT ? OFFSET ?
");

$stmt_eleves->bind_param("iii", $classe_id, $eleves_par_page, $offset);
$stmt_eleves->execute();
$eleves = $stmt_eleves->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!-- Sélection trimestre -->
<form method="GET" class="mb-3" onsubmit="return submitSelection(this)">
    <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
    <input type="hidden" name="cours_id" value="<?= $cours_id ?>">

    <div class="card shadow-sm">
        <div class="card-body">
            <label class="form-label fw-bold">Choisir le trimestre :</label>
            <select name="trimestre" class="form-select" required onchange="this.form.submit()">
                <option value="">-- Sélectionner --</option>
                <?php foreach ($liste_trimestres as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($trimestre == $t['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</form>

<?php endif; ?>

<?php if ($trimestre): ?>

<!-- Barre de recherche et informations -->
<div class="row mb-3">
    <div class="col-md-6">
        <input type="text" id="searchInput" class="form-control" placeholder="Rechercher un élève..." onkeyup="filterEleves()">
    </div>
    <div class="col-md-6 text-md-end">
        
    </div>
</div>

<!-- Tableau élèves -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pencil-square me-2"></i> Saisie des notes</span>
        <small>Page <?= $page ?> sur <?= $total_pages ?></small>
    </div>

    <div class="card-body">
        <!-- Formulaire d'enregistrement des notes (POST) -->
        <form method="POST">
            <input type="hidden" name="classe_id" value="<?= $classe_id ?>">
            <input type="hidden" name="cours_id" value="<?= $cours_id ?>">
            <input type="hidden" name="trimestre" value="<?= $trimestre ?>">

            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="tableEleves">
                    <thead class="table-light">
                        <tr>
                            <th width="60%">Élève</th>
                            <th width="40%">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eleves as $e): ?>
                        <tr>
                           <td class="eleve-name">
                                <i class="bi bi-person-circle me-2"></i>
                                <?= !empty($e['numero_ordre']) 
                                    ? htmlspecialchars($e['numero_ordre']) 
                                    : htmlspecialchars($e['nom'] . " " . $e['prenom']) ?>
                            </td>

                            <td>
                                <input type="number" step="0.1" min="0" max="300" 
                                       class="form-control note-input"
                                       name="notes[<?= $e['id'] ?>]"
                                       value="<?= htmlspecialchars(get_note($e['id'], $cours_id, $trimestre, $conn)) ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <nav aria-label="Navigation des pages">
                    <ul class="pagination pagination-sm mb-0">
                        <!-- Premier et précédent -->
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="javascript:void(0)" onclick="changePage(1)" aria-label="Première page">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="javascript:void(0)" onclick="changePage(<?= $page - 1 ?>)" aria-label="Page précédente">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>

                        <!-- Pages -->
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="javascript:void(0)" onclick="changePage(<?= $i ?>)">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <!-- Suivant et dernier -->
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="javascript:void(0)" onclick="changePage(<?= $page + 1 ?>)" aria-label="Page suivante">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="javascript:void(0)" onclick="changePage(<?= $total_pages ?>)" aria-label="Dernière page">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                
                <div class="page-info">
                    Page <?= $page ?> sur <?= $total_pages ?> 
                    (<?= $total_eleves ?> élève(s) au total)
                </div>
            </div>
            <?php endif; ?>

            <div class="text-end mt-3">
                <button class="btn btn-success btn-lg" onclick="return confirmSave()">
                    <i class="bi bi-check2-circle me-1"></i> Enregistrer les notes
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
function get_note($eleve_id, $cours_id, $trimestre, $conn) {
    global $annee_scolaire_id; // AJOUT
    $stmt = $conn->prepare("SELECT note FROM notes WHERE eleve_id = ? AND cours_id = ? AND trimestre = ? AND annee_scolaire_id = ?");
    $stmt->bind_param("iiii", $eleve_id, $cours_id, $trimestre, $annee_scolaire_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['note'] : '';
}

include("../footer.php");
?>