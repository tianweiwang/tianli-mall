(global["webpackJsonp"]=global["webpackJsonp"]||[]).push([["components/parabolaBall/ParabolaBall"],{"4fd5":function(t,e,n){"use strict";n.r(e);var r=n("70d9"),u=n.n(r);for(var a in r)["default"].indexOf(a)<0&&function(t){n.d(e,t,(function(){return r[t]}))}(a);e["default"]=u.a},"541f":function(t,e,n){"use strict";n.r(e);var r=n("d293"),u=n("4fd5");for(var a in u)["default"].indexOf(a)<0&&function(t){n.d(e,t,(function(){return u[t]}))}(a);var o,f=n("828b"),i=Object(f["a"])(u["default"],r["b"],r["c"],!1,null,null,null,!1,r["a"],o);e["default"]=i.exports},"70d9":function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.default=void 0;var r={props:{size:{type:Number,default:20},color:{type:String,default:"#f5222d"},zIndex:{type:Number,default:999},duration:{type:Number,default:500}},data:function(){return{dots:[]}},methods:{showBall:function(t){var e=this,n=t.start,r=(t.end,t.src);return new Promise((function(t){var u=e.dots.find((function(t){return!t.show}));u||(u={src:"",left:0,top:0,show:!1},e.dots.push(u));var a=e.duration,o=n.x-e.size/2,f=n.y-e.size/2,i=50-e.size/2,s=640-e.size/2,d=Date.now(),l=i-o,c=s-f,p=-2*l/(a*a)/5,b=Math.abs(p),v=l/a-p*a/2,h=c/a-b*a/2,w=function e(){var n=Date.now()-d,r=o+(v*n+p*n*n/2),i=f+(h*n+b*n*n/2);u.left=r,u.top=i,n<a?setTimeout(e):(u.show=!1,t())};u.src=r,u.show=!0,w()}))}}};e.default=r},d293:function(t,e,n){"use strict";var r;n.d(e,"b",(function(){return u})),n.d(e,"c",(function(){return a})),n.d(e,"a",(function(){return r}));var u=function(){var t=this,e=t.$createElement;t._self._c},a=[]}}]);
;(global["webpackJsonp"] = global["webpackJsonp"] || []).push([
    'components/parabolaBall/ParabolaBall-create-component',
    {
        'components/parabolaBall/ParabolaBall-create-component':(function(module, exports, __webpack_require__){
            __webpack_require__('df3c')['createComponent'](__webpack_require__("541f"))
        })
    },
    [['components/parabolaBall/ParabolaBall-create-component']]
]);
