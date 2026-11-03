const dialog = document.querySelector('#dialog');
const content = document.querySelector('#dialog-content');
const title = document.querySelector('#dialog-title');
let toastTimer;
function notify(text) { const toast=document.querySelector('#toast'); toast.textContent=text; toast.hidden=false; clearTimeout(toastTimer); toastTimer=setTimeout(()=>toast.hidden=true,4000); }
document.querySelector('[data-close]').addEventListener('click',()=>dialog.close());
document.addEventListener('click',event=>{
 const button=event.target.closest('[data-action]'); if(!button)return;
 const box=button.closest('.box'); const name=box?.dataset.name || 'Nouvelle box';
 const action=button.dataset.action;
 if(action==='cec'||action==='refresh'){notify('Démonstration : état actualisé. Aucune requête envoyée aux box.'); if(box&&!box.classList.contains('offline'))box.querySelector('.checked').textContent='Vérifié à l’instant';return;}
 title.textContent=action==='add'?'Ajouter une box':name;
 dialog.classList.toggle('preview-dialog',action==='preview');
 if(action==='preview'){content.replaceChildren(button.querySelector('.screen-preview').cloneNode(true));}
 else if(action==='add'){content.innerHTML='<p class="dialog-note">Démonstration visuelle · aucune box ne sera enregistrée.</p><form method="dialog"><label>Nom<input required placeholder="Écran accueil"></label><label>Adresse IP ou DNS<input required placeholder="box.exemple.local"></label><label>Clé API<input type="password" placeholder="Clé d’accès"></label><button class="primary" type="submit">Ajouter</button></form>';}
 else{content.innerHTML='<p class="dialog-note">Démonstration visuelle · aucune commande envoyée.</p><form method="dialog"><label>Mode d’affichage<select><option>Automatique</option><option>Allumée</option><option>En veille</option></select></label><div class="form-row"><label>Allumage<input type="time" value="07:00"></label><label>Mise en veille<input type="time" value="18:00"></label></div><label class="weekend"><input type="checkbox"> Allumage le week-end</label><button class="primary" type="submit">Enregistrer</button></form><div class="restart-actions"><button data-demo-restart>Redémarrer l’affichage</button><button data-demo-restart>Redémarrer la box</button></div>';}
 dialog.showModal();
});
content.addEventListener('submit',()=>notify('Démonstration : aucun réglage réel modifié.'));
content.addEventListener('click',e=>{if(e.target.closest('[data-demo-restart]'))notify('Démonstration : aucun redémarrage envoyé.');});
document.querySelector('#auto').addEventListener('change',e=>notify(e.target.checked?'Actualisation activée dans la maquette.':'Actualisation suspendue dans la maquette.'));
