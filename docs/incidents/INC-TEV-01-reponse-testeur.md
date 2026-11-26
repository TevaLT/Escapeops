# Réponse à la testeuse (commentaire du ticket #13)

---

Bonjour Élodie,

Merci pour votre signalement du 26/11 concernant l’option sur **Le Cabinet des Ombres**. Voici ce que nous avons vérifié.

**Ce que nous avons fait**

Nous avons refait votre manipulation sur une copie de test de l’application, avec des groupes fictifs : prise d’une option, onglet laissé ouvert **15 minutes et 20 secondes** sans y toucher, puis clic sur « Confirmer ». Nous avons aussi essayé une quinzaine de variantes : confirmation juste avant et juste après la fin du délai, depuis un autre navigateur, et un autre groupe qui réserve le même créneau entre-temps.

À chaque fois, nous avons noté l’heure, ce qui s’affichait à l’écran, et nous avons vérifié directement dans la base de données si la réservation était enregistrée.

**Ce que nous avons constaté**

- Après 15 minutes, l’option **expire**. Le clic sur « Confirmer » est alors **refusé** : **rien n’est enregistré**. C’est très probablement ce qui vous est arrivé, puisque votre onglet était resté ouvert plus d’un quart d’heure.
- Une fois l’option expirée, le créneau redevient **libre** pour les autres. Il était donc normal de le voir libre sur votre téléphone.
- Une option confirmée dans les 15 minutes est bien enregistrée. Dans aucun essai, deux groupes n’ont obtenu le même créneau.
- L’heure de votre ordinateur, en avance de quelques minutes, n’a aucun effet : l’application se fie uniquement à l’heure de son serveur.

**Ce qui n’allait pas**

Vous avez raison sur un point : l’écran affiché après un refus **ressemble trop à un écran de confirmation**. Il garde le même titre, le récapitulatif de la réservation et le code de suivi. Le refus n’apparaît que dans un bandeau. On peut facilement croire que la réservation est faite. Nous avons aussi repéré que le bouton « Libérer le créneau » restait affiché sur une option déjà expirée, avec un message inexact.

Ces deux écrans **ont été corrigés** : en cas de refus, la page affiche désormais clairement « Réservation non enregistrée : option expirée » et indique si le créneau est encore libre ou s’il a été repris par un autre groupe. Le bouton « Libérer le créneau » n’apparaît plus sur une option expirée. La correction est disponible dans la version *[À COMPLÉTER : version ou commit]*.

**Pour la prochaine fois**

Si quelque chose vous semble anormal, notez simplement :
- **l’heure** à laquelle vous avez pris l’option et celle du clic ;
- le **code de suivi** de la réservation (affiché sur la page de la réservation) ;
- une **capture d’écran** de la page qui s’est affichée.

Avec ces trois éléments, nous retrouvons tout de suite la réservation concernée. Pour savoir si une réservation est bien enregistrée, rouvrez sa page : l’état « Confirmée » doit y être affiché.

Merci encore pour ce retour, qui nous a permis d’améliorer l’application.

Cordialement,  
Teva — équipe EscapeOps

---
