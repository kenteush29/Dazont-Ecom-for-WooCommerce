<?php
/**
 * « Il n'y avait rien à faire ici » n'est pas une panne.
 *
 * « Sniper veils et Tactical backpack covers sont en review avec des erreurs.
 * Ça sème la confusion, en fait, ça dit que le module ne fonctionne pas bien,
 * du point de vue utilisateur. Et c'est énervant. Pour les autres dans la
 * liste d'attente, travail parfait. »
 *
 * Les deux lignes rouges n'étaient pas des pannes. Sur « Sniper veils » les
 * garde-fous avaient refusé une réécriture qui ne tenait pas debout et une
 * ancre qui ne nommait pas sa cible : le module a fait exactement son travail.
 * Sur « Tactical backpack covers » le modèle avait regardé la liste et conclu
 * qu'aucune page n'était vraiment proche. Une conclusion, pas un échec.
 *
 * Or les deux atterrissaient dans la même case que « le service n'a pas
 * répondu » — en rouge, dans la liste de ce qui attend une décision, à côté de
 * seize pages parfaitement traitées. Du point de vue de la boutique, ça dit
 * « ce module est cassé », ce qui est faux et décourage d'ouvrir l'écran.
 *
 * D'où ce type d'incident à part. Ce qu'il signale se range en « rien à
 * faire » : la page est marquée comme vue, la raison reste lisible pour qui la
 * demande, et la liste d'attente ne contient plus que ce qui attend vraiment
 * quelque chose de quelqu'un.
 *
 * Ce qui reste une panne : le service qui ne répond pas, le budget atteint, la
 * page disparue, une réponse illisible. Là il y a quelque chose à réparer, et
 * le rouge est mérité.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Le module a regardé et conclu qu'il n'y avait rien à poser.
 *
 * Porté par une exception parce que le refus peut venir de six niveaux plus
 * bas — le choix de l'ancre, la relecture d'une réécriture, le tri des cibles
 * — et qu'un code de retour devrait être reconduit à la main par chacun d'eux,
 * ce qui est exactement la chaîne qu'on oublie un jour.
 */
final class DZE_Nothing_To_Do extends RuntimeException {}
