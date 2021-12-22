<?php
/*
  Vérifie id de login est valide et correspond au joueur inscrit sur la partie du côté indiqué (partie, cote)
  vérifie que la partie en est au stade indiqué par les param "tour" et "trait"
  Si le paramètre optionnel fourni est correct (tour indiqué = tour courant, joueur a bien le trait et rang coup valide)
    alors arbitrage est lancé pour jouer le coup et rédiger une nouvelle situation

  entrée :
    partie : id de la partie en cours
    cote : 1 si c'est le blanc qui fait la requete, 2 si c'est le noir, 0 si arbitre
    tour : numéro du tour, le premier tour est le tour 1
    trait : 1 si c'est au blancs de jouer, 2 si c'est aux noirs
    coup (optionnel) : rang du coup joué dans le tableau de coups du CR précédent

  sortie :
    erreur si besoin
    si aucune MAJ disponible : {"ras":1}
    sinon : contenu de la MAJ
*/

$link = mysqli_connect('mysql-kevineuh.alwaysdata.net', 'kevineuh', 'root', 'kevineuh_chess_wihou');
//Vérification du lien
if (!$link) {
  echo json_encode(array('erreur' => "Erreur de connexion à la base de données"));
  die('Erreur de connexion');
}
//Prévention de potentiels problèmes d'encodages
mysqli_set_charset($link, "utf8");


// on récupère les données en entrée
$id_joueur = $_GET['id_joueur'];
$partie = $_GET['partie'];
$cote = $_GET['cote'];
$tour = $_GET['tour'];
$trait = $_GET['trait'];

// on effectue toutes les vérifications avant de répartir la tache vers différentes fonctions selon le cas
$requete = "SELECT j1,j2,tour,trait FROM parties WHERE id = $partie";
if ($result = mysqli_query($link,$requete)) {
  while ($ligne = mysqli_fetch_assoc($result)) {
    // on vérifie que le joueur correspond au bon côté
    if (($id_joueur==$ligne['j1'] AND $cote==2) OR ($id_joueur==$ligne['j2'] AND $cote==1)){
      // on vérifie que la partie en est au stade indiqué par les param "tour" et "trait"
      if ($tour==$ligne['tour'] AND $trait==$ligne['trait']) { // A JOUR
        // si on est à jour c'est qu'on veut jouer ou que c'est encore à l'adversaire de jouer
        if (isset($_GET['coup'])) { // le client a lancé un coup
          $coup = $_GET['coup'];
          echo "on a joué";
        } else if ($cote!=$ligne['trait']) { // si on attend le coup de l'adversaire
          echo json_encode(array('ras' => 1));
        } else {
          echo json_encode(array('erreur' => "aucune des situations ne correspond"));
        }
      } else { // si on n'est pas à jour c'est que l'adversaire a joué => MAJ
        MAJ($cote);
      }
    } else {
      echo json_encode(array('erreur' => "Le joueur ne correspond pas au côté en entrée."));
    }
  }
} else {
  echo json_encode(array('erreur' => "Erreur de requête de base de données."));
}


function MAJ($cote) {
  /*
  L'adversaire a joué, on veut renvoyer le tour et le trait maj,
  le coup de l'adversaire ainsi que les coups possibles désormais
  */
  $retour = array("coups" => [],"vues"=>[],"il_joue" => [0,0,0,0]);
  // on récupère la nouvelle disposition sur la base de données
  $partie = $_GET['partie'];
  $requete_plateau = "SELECT plateau FROM parties WHERE id = $partie";
  $link = mysqli_connect('mysql-kevineuh.alwaysdata.net', 'kevineuh', 'root', 'kevineuh_chess_wihou');
  if ($result = mysqli_query($link,$requete_plateau)) {
    $plateau = json_decode(mysqli_fetch_assoc($result)['plateau'],true);
    // on parcourt toutes les pièces de la couleur du joueur et on ajoute
    // les possibilités dans la liste des possibilités
    foreach ($plateau as $piece => $position) { // on parcourt toutes les pièces
      if ($piece[1] == $cote) { // on ne prend que celles du côté du joueur courant
        // on va alors calculer les possibilités de chaque pièce selon sa valeur
        if ($piece[0] == 'P') { // il s'agit d'un pion
          $retour_pion = pion($piece,$plateau,$cote,$retour);
          $retour["coups"] = array_merge($retour["coups"],$retour_pion["coups"]);
          $retour["vues"] = array_merge($retour["vues"],$retour_pion["vues"]);
        } else if ($piece[0] == 'F') { // il s'agit d'un fou
          $retour_fou = fou($piece,$plateau,$cote,$retour);
          $retour["coups"] = array_merge($retour["coups"],$retour_fou["coups"]);
        } else if ($piece[0] == 'T') { // il s'agit d'une tour
          $retour_tour = tour($piece,$plateau,$cote,$retour);
          $retour["coups"] = array_merge($retour["coups"],$retour_tour["coups"]);
        } else if ($piece[0] == 'D') { // il s'agit de la dame
          $retour_dame = dame($piece,$plateau,$cote,$retour);
          $retour["coups"] = array_merge($retour["coups"],$retour_dame["coups"]);
        } else if ($piece[0] == 'C') { // il s'agit d'un cavalier
          $retour_cavalier = cavalier($piece,$plateau,$cote,$retour);
          $retour["coups"] = array_merge($retour["coups"],$retour_cavalier["coups"]);
        } else { // il s'agit du roi
          $retour_roi = roi($piece,$plateau,$cote,$retour);
          $retour["coups"] = array_merge($retour["coups"],$retour_roi["coups"]);
        }
      }
    }


    // on va alors préparer la partie de la réponse correspondant au coup joué par l'adversaire "il_joue"
    $requete_histos = "SELECT histo1,histo2 FROM parties WHERE id = $partie";
    if ($result_histos = mysqli_query($link,$requete_histos)) {
      // récupération des historiques des joueurs
      $histos = mysqli_fetch_assoc($result_histos);
      if ($cote == 1) {
        $histo_joueur = json_decode($histos['histo1'],true);
        $histo_adv = json_decode($histos['histo2'],true);
      } else {
        $histo_joueur = json_decode($histos['histo2'],true);
        $histo_adv = json_decode($histos['histo1'],true);
      }
      // récupération des coordonnées du dernier coup de l'adversaire
      $coords_coup_adv = end($histo_adv["histo"])["je_joue"];
      // on teste alors si on peut ajouter les coordonnées et la nature ou si elles restent cachées
      $retour = verif_coords_depart_vues($histo_joueur,$histo_adv,$coords_coup_adv,$retour);
      $retour = verif_coords_fin_vues($coords_coup_adv,$retour,$plateau);

    } else {
      echo json_encode(array("erreur" => "Erreur de requête de base de données."));
    }

    echo json_encode($retour);
  } else {
    echo json_encode(array("erreur" => "Erreur de requête de base de données."));
  }
}


function verif_coords_depart_vues($histo_joueur,$histo_adv,$coords_coup_adv,$retour) {
  /*
  vérifie si la position de départ de coup joué par l'adversaire est visible par le joueur,
  renvoie la variable $retour mise à jour
  exploration des cases vues par le joeur avant le coup de l'adversaire
  on va les trouver dans le dernier "je_joue" avec la clé vue (cases vues après le coup du joeur)
  et dans le dernier "il_joue" avec les clés coups (toutes les possibilités) et "vues" (pieces blaquant les pions)
  */
  foreach(array(array(1,"vues"),array(2,"vues"),array(2,"coups")) as $explore_histo) {
    $i = $explore_histo[0]; // 1 correspond à n-1, c'est à dire le dernier élément de la liste histo, 2 correspond à l'avant dernier élément
    $cle = $explore_histo[1]; // correspond à "vues" ou "coups"
    $vues_par_joueur = $histo_joueur["histo"][count($histo_joueur["histo"])-$i][$cle];
    if (isset($vues_par_joueur)) { // on récupère une par une les listes de cases vues par le joueur
      foreach($vues_par_joueur as $case_exploree) { // on parcourt ces cases
        if ((($coords_coup_adv[0]==$case_exploree[0]) && ($coords_coup_adv[1]==$case_exploree[1]))
        || (($cle=="coups") && ($coords_coup_adv[0]==$case_exploree[2]) && ($coords_coup_adv[1]==$case_exploree[3]))) {
          // si on retrouve les deux mêmes coords, c'est que cette case est vue
          // s'il s'agit d'une liste "coords" il faut prendre en compte les coords d'index 2 et 3
          // on peut ajouter les coordonnées au retour
          $retour["il_joue"][0] = $coords_coup_adv[0];
          $retour["il_joue"][1] = $coords_coup_adv[1];
          break 2; // il est alors inutile de parcourir les autres coordonnées
        }
      }
    }
  }
  return $retour;
}


function verif_coords_fin_vues($coords_coup_adv,$retour,$plateau) {
  /*
  vérifie si la position de fin du coup joué par l'adversaire est visible par le joueur,
  renvoie la variable $retour mise à jour
  exploration des cases vues par le joueur au moment courant, calculées dans le retour dans "coups" et "vues"
  */
  foreach(array("coups","vues") as $cle) {
    $vues_par_joueur = $retour[$cle];
    if (isset($vues_par_joueur)) { // on récupère une par une les listes de cases vues par le joueur actuellement
      foreach($vues_par_joueur as $case_exploree) { // on parcourt ces cases
        if ((($coords_coup_adv[2]==$case_exploree[0]) && ($coords_coup_adv[3]==$case_exploree[1]))
        || (($cle=="coups") && ($coords_coup_adv[2]==$case_exploree[2]) && ($coords_coup_adv[3]==$case_exploree[3]))) {
          // si on retrouve les deux mêmes coords, c'est que cette case est vue
          // s'il s'agit d'une liste "coords" il faut prendre en compte les coords d'index 2 et 3
          // on peut ajouter les coordonnées au retour
          $retour["il_joue"][2] = $coords_coup_adv[2];
          $retour["il_joue"][3] = $coords_coup_adv[3];
          $retour["nature"] = occupee($plateau,$coords_coup_adv[2],$coords_coup_adv[3])[0]; // on ajoute la valeur de la pièce
          break 2; // il est alors inutile de parcourir les autres coordonnées
        }
      }
    }
  }
  return $retour;
}


function pion($piece,$plateau,$cote) {
  $coups_par_pion = array();
  $vues_par_pion = array();
  $i_p = $plateau[$piece]["i"]; // on récupère la coord i du pion
  $j_p = $plateau[$piece]["j"]; // et sa coordonnée j
  // on va tester la couleur du pion
  if ($cote == 1) { // s'il s'agit d'un pion blanc
    $sens = +1; // on va 'avancer' dans le sens i positif
  } else { // si on est du côté des noirs
    $sens = -1; // on va 'avancer' dans le sens i négatif
  }
  // on va parcourir les deux cases devant le pion
  foreach (array($i_p+1*$sens,$i_p+2*$sens) as $i_expl) { // on teste les cases devant le pion
    $occupee = occupee($plateau,$i_expl,$j_p);
    if ($occupee == false) { // s'il n'y a rien sur le chemin
      $coups_par_pion[] = [$i_p,$j_p,$i_expl,$j_p];
    } else if (($i_expl==1 && $cote==1) || ($i_expl==8 && $cote==2)) { // promotion
      // on ajoute les 4 promotions possibles
      $coups_par_pion[] = [$i_p,$j_p,$i_expl,$j_p,"F"];
      $coups_par_pion[] = [$i_p,$j_p,$i_expl,$j_p,"T"];
      $coups_par_pion[] = [$i_p,$j_p,$i_expl,$j_p,"R"];
      $coups_par_pion[] = [$i_p,$j_p,$i_expl,$j_p,"D"];
    } else { // si on rencontre un obstacle
      if ($occupee[1]!=$cote) { // si l'obstacle n'est pas de la couleur du joueur
        $vues_par_pion[] = [$i_p,$j_p,$i_expl,$j_p,$occupee[0]]; // on enregistre cet obstacle dans la valeur "vues"
      }
      break; // puis on coupe la trajectoire
    }
  }
  // puis on teste les cases en diagonale
  foreach (array($j_p-1,$j_p+1) as $j_expl) {
    $occupee = occupee($plateau,$i_p+1*$sens,$j_expl);
    if ($occupee != false) { // s'il y a bien une pièce sur cette case
      if ($occupee[1]!=$cote) { // et si elle n'est pas de la couleur du joueur
        $coups_par_pion[] = [$i_p,$j_p,$i_p+1,$j_expl,$occupee[0]];
      }
    }
  }
  // on renvoie alors le résultat
  return array("coups"=>$coups_par_pion,"vues"=>$vues_par_pion);
}


function cavalier($piece,$plateau,$cote) {
  $coups_par_cavalier = array();
  $i_c = $plateau[$piece]["i"];
  $j_c = $plateau[$piece]["j"];
  // on va parcourir les cases atteintes par le cavalier
  foreach(array(array($i_c-1,$j_c-2),array($i_c+1,$j_c-2),array($i_c+2,$j_c-1),
                array($i_c+2,$j_c+1),array($i_c+1,$j_c+2),array($i_c-1,$j_c+2),
                array($i_c-2,$j_c+1),array($i_c-2,$j_c-1)) as $coord_expl) {
    $i_expl = $coord_expl[0];
    $j_expl = $coord_expl[1];
    if (!(($i_expl<=0)||($i_expl>=9)||($j_expl<=0)||($j_expl>=9))) { // si la case explorée est bien sur l'échiquier
      $occupee = occupee($plateau,$i_expl,$j_expl); // on recherche si elle est occupée
      if ($occupee == false) { // s'il n'y a rien sur cette case
        $coups_par_cavalier[] = [$i_c,$j_c,$i_expl,$j_expl];
      } else { // si on rencontre un obstacle
        if ($occupee[1]!=$cote) { // si l'obstacle n'est pas de la couleur du joueur
          $coups_par_cavalier[] = [$i_c,$j_c,$i_expl,$j_expl,$occupee[0]]; // la pièce peut prendre celle sur la case explorée
        }
      }
    }
  }
  return array("coups"=>$coups_par_cavalier); // on renvoie alors le résultat
}


function roi($piece,$plateau,$cote) {
  $coups_par_roi = array();
  $i_r = $plateau[$piece]["i"];
  $j_r = $plateau[$piece]["j"];
  // on va parcourir les cases atteintes par le cavalier
  foreach(array(array($i_r-1,$j_r-1),array($i_r,$j_r-1),array($i_r+1,$j_r-1),
                array($i_r-1,$j_r+1),array($i_r,$j_r+1),array($i_r+1,$j_r+1),
                array($i_r-1,$j_r),array($i_r+1,$j_r)) as $coord_expl) {
    $i_expl = $coord_expl[0];
    $j_expl = $coord_expl[1];
    if (!(($i_expl<=0)||($i_expl>=9)||($j_expl<=0)||($j_expl>=9))) { // si la case explorée est bien sur l'échiquier
      $occupee = occupee($plateau,$i_expl,$j_expl); // on recherche si elle est occupée
      if ($occupee == false) { // s'il n'y a rien sur cette case
        $coups_par_roi[] = [$i_r,$j_r,$i_expl,$j_expl];
      } else { // si on rencontre un obstacle
        if ($occupee[1]!=$cote) { // si l'obstacle n'est pas de la couleur du joueur
          $coups_par_roi[] = [$i_r,$j_r,$i_expl,$j_expl,$occupee[0]]; // la pièce peut prendre celle sur la case explorée
        }
      }
    }
  }
  return array("coups"=>$coups_par_roi); // on renvoie alors le résultat
}


function fou($piece,$plateau,$cote) {
  $coups_par_fou = array();
  // on va parcourir les cases en diagonale
  foreach (array(array(-1,-1),array(+1,-1),array(-1,+1),array(+1,+1)) as $direction) {
    // on a 4 directions disponibles à tester
    $coups_par_fou = array_merge($coups_par_fou,explore_direction($direction,$plateau,$cote,$piece));
  }
  return array("coups"=>$coups_par_fou); // on renvoie alors le résultat
}


function tour($piece,$plateau,$cote) {
  $coups_par_tour = array();
  // on va parcourir les cases atteintes par la tour
  foreach (array(array(-1,0),array(+1,0),array(0,-1),array(0,+1)) as $direction) {
    // on a 4 directions disponibles
    $coups_par_tour = array_merge($coups_par_tour,explore_direction($direction,$plateau,$cote,$piece));
  }
  return array("coups"=>$coups_par_tour); // on renvoie alors le résultat
}


function dame($piece,$plateau,$cote) {
  $coups_par_dame = array();
  // on va parcourir les cases atteintes par la tour
  foreach (array(array(-1,0),array(+1,0),array(0,-1),array(0,+1),array(-1,-1),array(+1,-1),array(-1,+1),array(+1,+1)) as $direction) {
    // on a 8 directions disponibles
    $coups_par_dame = array_merge($coups_par_dame,explore_direction($direction,$plateau,$cote,$piece));
  }
  return array("coups"=>$coups_par_dame); // on renvoie alors le résultat
}


function explore_direction($direction,$plateau,$cote,$piece) {
  /*
  simule l'exploration d'un fou, d'une tour ou d'une dame.
  Pour cela, prend en entrée la direction choisie, le pateau, le coté et la pièce de base
  Renvoie la liste des coups possibles par la pièce dans cette direction
  */
  $i = $plateau[$piece]["i"];
  $j = $plateau[$piece]["j"];
  $coups_par_piece_dans_direction = array();
  for ($incr=1;$incr<8;$incr++) { // on incrémente d'une case
    $i_expl = $i + $incr*$direction[0];
    $j_expl = $j + $incr*$direction[1];
    if (($i_expl<=0)||($i_expl>=9)||($j_expl<=0)||($j_expl>=9)) {
      break; // si on dépasse du bord on coupe la trajectoire dans cette direction
    } else { // si la case est bien sur l'échiquier
      $occupee = occupee($plateau,$i_expl,$j_expl); // on recherche si elle est occupée
      if ($occupee == false) { // s'il n'y a rien sur cette case
        $coups_par_piece_dans_direction[] = [$i,$j,$i_expl,$j_expl];
      } else { // si on rencontre un obstacle
        if ($occupee[1]!=$cote) { // si l'obstacle n'est pas de la couleur du joueur
          $coups_par_piece_dans_direction[] = [$i,$j,$i_expl,$j_expl,$occupee[0]]; // la pièce peut prendre celle sur la case explorée
        }
        break; // puis on coupe la trajectoire dans cette direction
      }
    }
  }
  return $coups_par_piece_dans_direction;
}


function occupee($plateau,$i_expl,$j_expl) {
  /*
  fonction permettant de tester si une position donnée sur un plateau donné est occupée par une pièce
  renvoie false si non, renvoie la pièce en question si oui
  */
  foreach ($plateau as $piece_testee => $coord_testes) { // on parcourt toutes les pièces
    $i_teste = $coord_testes["i"]; // récupération coordonnée i
    $j_teste = $coord_testes["j"]; // et j
    if (($i_expl==$i_teste) && ($j_expl==$j_teste)) {
      // si elle correspond aux coordonnées explorées
      // on coupe la fonction et on renvoie la pièce rencontrée
      return $piece_testee;
    }
  }
  return false;
}


?>
