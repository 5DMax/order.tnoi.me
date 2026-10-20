$id('login').addEventListener('submit',e=>{e.preventDefault();busy(e.submitter,async()=>{await api('/api/login',Object.fromEntries(new FormData(e.target)));location.href='/admin';});});
