/* Capa Koinonia animada: o laranja da arte "queima" como fogo (WebGL) + faíscas subindo.
   Camadas: koinonia-base (arte sem o laranja), koinonia-fogo (só o laranja, com transparência)
   e koinonia-mov (onde o fogo pode se mexer mais). Se o WebGL falhar, fica a imagem estática. */
(function () {
  const box = document.querySelector('.koi');
  if (!box) return;
  const cv = box.querySelector('.koi__gl');
  const fx = box.querySelector('.koi__fx');
  const IMG_W = 1586, IMG_H = 992;
  const FOCO = { x: 0.5, y: 0.42 };            // ponto que sempre fica visível (o logo)
  const calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const gl = cv.getContext('webgl', { premultipliedAlpha: false, antialias: false });
  if (!gl) return;

  const carregar = src => new Promise((ok, erro) => { const i = new Image(); i.onload = () => ok(i); i.onerror = erro; i.src = src; });

  const VS = `attribute vec2 p;varying vec2 v;void main(){v=p*.5+.5;v.y=1.-v.y;gl_Position=vec4(p,0,1);}`;
  const FS = `precision mediump float;
varying vec2 v;
uniform sampler2D tB,tF,tA;
uniform float t;
uniform vec2 sc,of;
float h(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
float n(vec2 p){vec2 i=floor(p),f=fract(p);f=f*f*(3.-2.*f);
 return mix(mix(h(i),h(i+vec2(1,0)),f.x),mix(h(i+vec2(0,1)),h(i+vec2(1,1)),f.x),f.y);}
float fbm(vec2 p){float s=0.,a=.5;for(int k=0;k<4;k++){s+=a*n(p);p*=2.03;a*=.5;}return s;}
void main(){
 vec2 uv=v*sc+of;                       // cobre a tela mantendo o foco
 float z=1.+.025*sin(t*.12);            // respiração lenta da cena
 uv=(uv-.5)/z+.5;
 vec3 base=texture2D(tB,uv).rgb;
 float a=texture2D(tA,uv).r;
 vec2 q=uv*vec2(1.6,1.);
 float n1=fbm(q*5.-vec2(0.,t*.55));
 float n2=fbm(q*5.+vec2(3.1,7.7)-vec2(0.,t*.75));
 float n3=fbm(q*11.-vec2(0.,t*1.4));
 vec2 d=vec2((n1-.5)*1.1+(n3-.5)*.35,(n2-.35)*.9)*a*.038;   // chama sobe: puxa a amostra de baixo
 vec4 f=texture2D(tF,uv+d);
 float fl=.9+.1*sin(t*6.+n1*9.)+.06*(n3-.5);                 // cintilar
 vec3 fc=f.rgb*fl;
 vec3 col=mix(base,fc,f.a);
 // brilho quente em volta do fogo
 float g=0.;
 for(int k=0;k<6;k++){float an=float(k)*1.047+t*.3;g+=texture2D(tF,uv+d+vec2(cos(an),sin(an))*.012).a;}
 g/=6.;
 float pulso=.75+.25*sin(t*2.3+n2*4.);
 col+=vec3(1.,.42,.08)*g*(1.-f.a)*.16*pulso*(.4+a);
 gl_FragColor=vec4(col,1.);
}`;

  function shader(tipo, src) { const s = gl.createShader(tipo); gl.shaderSource(s, src); gl.compileShader(s); return s; }
  const prog = gl.createProgram();
  gl.attachShader(prog, shader(gl.VERTEX_SHADER, VS));
  gl.attachShader(prog, shader(gl.FRAGMENT_SHADER, FS));
  gl.linkProgram(prog);
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) return;
  gl.useProgram(prog);
  const buf = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, buf);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
  const loc = gl.getAttribLocation(prog, 'p');
  gl.enableVertexAttribArray(loc);
  gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
  const U = k => gl.getUniformLocation(prog, k);

  function textura(img, unidade, nome) {
    const tx = gl.createTexture();
    gl.activeTexture(gl.TEXTURE0 + unidade);
    gl.bindTexture(gl.TEXTURE_2D, tx);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img);
    gl.uniform1i(U(nome), unidade);
  }

  // mapeamento "cover" com foco no logo
  let sc = [1, 1], of = [0, 0], W = 0, H = 0;
  function medir() {
    const r = box.getBoundingClientRect();
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = Math.round(r.width * dpr); H = Math.round(r.height * dpr);
    cv.width = W; cv.height = H;
    fx.width = W; fx.height = H;
    gl.viewport(0, 0, W, H);
    const telaAR = r.width / r.height, imgAR = IMG_W / IMG_H;
    if (telaAR > imgAR) { sc = [1, imgAR / telaAR]; } else { sc = [telaAR / imgAR, 1]; }
    of = [Math.min(Math.max(FOCO.x - sc[0] / 2, 0), 1 - sc[0]), Math.min(Math.max(FOCO.y - sc[1] / 2, 0), 1 - sc[1])];
    gl.uniform2f(U('sc'), sc[0], sc[1]);
    gl.uniform2f(U('of'), of[0], of[1]);
  }

  // faíscas: nascem em pontos do laranja e sobem
  let pontos = [];
  function pontosDoFogo(img) {
    const c = document.createElement('canvas'); c.width = 200; c.height = 125;
    const x = c.getContext('2d'); x.drawImage(img, 0, 0, 200, 125);
    const d = x.getImageData(0, 0, 200, 125).data;
    for (let y = 0; y < 125; y++) for (let i = 0; i < 200; i++) if (d[(y * 200 + i) * 4 + 3] > 200 && y < 85) pontos.push([i / 200, y / 125]);
  }
  const ctx = fx.getContext('2d');
  const brasas = [];
  function nova() {
    const p = pontos[(Math.random() * pontos.length) | 0];
    return { x: p[0], y: p[1], vx: (Math.random() - .5) * .012, vy: -(.03 + Math.random() * .05), r: .6 + Math.random() * 1.8, vida: 0, dur: 1.6 + Math.random() * 2.4, fase: Math.random() * 6 };
  }
  function desenharBrasas(dt, t) {
    ctx.clearRect(0, 0, W, H);
    if (!pontos.length) return;
    while (brasas.length < 70) brasas.push(nova());
    const dpr = W / box.clientWidth;
    ctx.globalCompositeOperation = 'lighter';
    for (let i = brasas.length - 1; i >= 0; i--) {
      const b = brasas[i];
      b.vida += dt;
      if (b.vida > b.dur) { brasas[i] = nova(); continue; }
      b.x += (b.vx + Math.sin(t * 2 + b.fase) * .004) * dt;
      b.y += b.vy * dt;
      const sx = ((b.x - of[0]) / sc[0]) * W, sy = ((b.y - of[1]) / sc[1]) * H;
      const k = b.vida / b.dur, a = Math.sin(Math.PI * k) * .9;
      const rr = b.r * dpr * (1 - k * .5);
      if (!isFinite(sx + sy + rr) || rr <= 0 || a <= 0) { brasas[i] = nova(); continue; }
      const gr = ctx.createRadialGradient(sx, sy, 0, sx, sy, rr * 4);
      gr.addColorStop(0, `rgba(255,214,140,${a})`);
      gr.addColorStop(.35, `rgba(255,120,20,${a * .7})`);
      gr.addColorStop(1, 'rgba(255,80,0,0)');
      ctx.fillStyle = gr;
      ctx.beginPath(); ctx.arc(sx, sy, rr * 4, 0, 6.283); ctx.fill();
    }
  }

  Promise.all([
    carregar('assets/koinonia-base.webp?v=1'),
    carregar('assets/koinonia-fogo.webp?v=1'),
    carregar('assets/koinonia-mov.png?v=1'),
  ]).then(([b, f, a]) => {
    textura(b, 0, 'tB'); textura(f, 1, 'tF'); textura(a, 2, 'tA');
    pontosDoFogo(f);
    medir();
    window.addEventListener('resize', medir);
    const uT = U('t');
    let visivel = true, ant = performance.now(), t0 = ant;
    new IntersectionObserver(e => { visivel = e[0].isIntersecting; }).observe(box);
    function quadro(agora) {
      const dt = Math.max(0, Math.min((agora - ant) / 1000, .05)); ant = agora;
      const t = calmo ? 0 : (agora - t0) / 1000;
      if (visivel) {
        gl.uniform1f(uT, t);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
        if (!calmo) desenharBrasas(dt, t);
      }
      box.classList.add('is-vivo');
      if (!calmo) requestAnimationFrame(quadro);
    }
    requestAnimationFrame(quadro);
  }).catch(() => {});
})();
