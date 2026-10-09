CREATE TYPE "public"."papel" AS ENUM('observador', 'editor', 'administrador');--> statement-breakpoint
CREATE TYPE "public"."precisao_data" AS ENUM('dia', 'mes', 'ano');--> statement-breakpoint
CREATE TYPE "public"."qualificador_data" AS ENUM('exata', 'cerca', 'antes', 'depois', 'entre');--> statement-breakpoint
CREATE TYPE "public"."sexo" AS ENUM('M', 'F', 'D');--> statement-breakpoint
CREATE TYPE "public"."tipo_documento" AS ENUM('certidao_nascimento', 'certidao_casamento', 'foto', 'obito', 'passaporte', 'outro');--> statement-breakpoint
CREATE TYPE "public"."tipo_evento" AS ENUM('batismo', 'imigracao', 'naturalizacao', 'residencia', 'sepultamento', 'ocupacao', 'outro');--> statement-breakpoint
CREATE TYPE "public"."tipo_uniao" AS ENUM('casamento', 'uniao_estavel', 'outro');--> statement-breakpoint
CREATE TYPE "public"."tipo_vinculo" AS ENUM('biologico', 'adotivo', 'padrasto', 'tutela');--> statement-breakpoint
CREATE TABLE "account" (
	"id" text PRIMARY KEY NOT NULL,
	"account_id" text NOT NULL,
	"provider_id" text NOT NULL,
	"user_id" text NOT NULL,
	"access_token" text,
	"refresh_token" text,
	"id_token" text,
	"access_token_expires_at" timestamp,
	"refresh_token_expires_at" timestamp,
	"scope" text,
	"password" text,
	"created_at" timestamp DEFAULT now() NOT NULL,
	"updated_at" timestamp NOT NULL
);
--> statement-breakpoint
CREATE TABLE "arvores" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "arvores_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"nome" text NOT NULL,
	"descricao" text,
	"criado_por" text NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "auditoria" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "auditoria_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"arvore_id" integer,
	"usuario_id" text,
	"acao" text NOT NULL,
	"entidade" text,
	"entidade_id" integer,
	"detalhes" jsonb,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "documentos" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "documentos_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"arvore_id" integer NOT NULL,
	"pessoa_id" integer NOT NULL,
	"tipo" "tipo_documento" NOT NULL,
	"caminho_arquivo" text NOT NULL,
	"descricao" text,
	"criado_por" text NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "eventos" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "eventos_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"arvore_id" integer NOT NULL,
	"pessoa_id" integer NOT NULL,
	"uniao_id" integer,
	"tipo" "tipo_evento" DEFAULT 'outro' NOT NULL,
	"data" date,
	"data_qualificador" "qualificador_data" DEFAULT 'exata' NOT NULL,
	"data_precisao" "precisao_data" DEFAULT 'dia' NOT NULL,
	"data_ate" date,
	"local" text,
	"descricao" text,
	"documento_id" integer,
	"criado_por" text NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "filiacoes" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "filiacoes_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"arvore_id" integer NOT NULL,
	"filho_id" integer NOT NULL,
	"pai_id" integer,
	"mae_id" integer,
	"tipo_pai" "tipo_vinculo" DEFAULT 'biologico' NOT NULL,
	"tipo_mae" "tipo_vinculo" DEFAULT 'biologico' NOT NULL,
	"criado_por" text NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "membros_arvore" (
	"arvore_id" integer NOT NULL,
	"usuario_id" text NOT NULL,
	"papel" "papel" NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "membros_arvore_arvore_id_usuario_id_pk" PRIMARY KEY("arvore_id","usuario_id")
);
--> statement-breakpoint
CREATE TABLE "pessoas" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "pessoas_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"arvore_id" integer NOT NULL,
	"nome_completo" text NOT NULL,
	"sexo" "sexo" DEFAULT 'D' NOT NULL,
	"foto" text,
	"data_nascimento" date,
	"data_nascimento_qualificador" "qualificador_data" DEFAULT 'exata' NOT NULL,
	"data_nascimento_precisao" "precisao_data" DEFAULT 'dia' NOT NULL,
	"data_nascimento_ate" date,
	"local_nascimento" text,
	"data_falecimento" date,
	"data_falecimento_qualificador" "qualificador_data" DEFAULT 'exata' NOT NULL,
	"data_falecimento_precisao" "precisao_data" DEFAULT 'dia' NOT NULL,
	"data_falecimento_ate" date,
	"local_falecimento" text,
	"biografia" text,
	"criado_por" text NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "session" (
	"id" text PRIMARY KEY NOT NULL,
	"expires_at" timestamp NOT NULL,
	"token" text NOT NULL,
	"created_at" timestamp DEFAULT now() NOT NULL,
	"updated_at" timestamp NOT NULL,
	"ip_address" text,
	"user_agent" text,
	"user_id" text NOT NULL,
	CONSTRAINT "session_token_unique" UNIQUE("token")
);
--> statement-breakpoint
CREATE TABLE "unioes" (
	"id" integer PRIMARY KEY GENERATED ALWAYS AS IDENTITY (sequence name "unioes_id_seq" INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1),
	"arvore_id" integer NOT NULL,
	"pessoa1_id" integer NOT NULL,
	"pessoa2_id" integer NOT NULL,
	"tipo" "tipo_uniao" DEFAULT 'casamento' NOT NULL,
	"data_inicio" date,
	"data_inicio_qualificador" "qualificador_data" DEFAULT 'exata' NOT NULL,
	"data_inicio_precisao" "precisao_data" DEFAULT 'dia' NOT NULL,
	"data_inicio_ate" date,
	"local_inicio" text,
	"data_fim" date,
	"data_fim_qualificador" "qualificador_data" DEFAULT 'exata' NOT NULL,
	"data_fim_precisao" "precisao_data" DEFAULT 'dia' NOT NULL,
	"data_fim_ate" date,
	"criado_por" text NOT NULL,
	"criado_em" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "ck_unioes_par_normalizado" CHECK ("unioes"."pessoa1_id" < "unioes"."pessoa2_id")
);
--> statement-breakpoint
CREATE TABLE "user" (
	"id" text PRIMARY KEY NOT NULL,
	"name" text NOT NULL,
	"email" text NOT NULL,
	"email_verified" boolean DEFAULT false NOT NULL,
	"image" text,
	"created_at" timestamp DEFAULT now() NOT NULL,
	"updated_at" timestamp DEFAULT now() NOT NULL,
	CONSTRAINT "user_email_unique" UNIQUE("email")
);
--> statement-breakpoint
CREATE TABLE "verification" (
	"id" text PRIMARY KEY NOT NULL,
	"identifier" text NOT NULL,
	"value" text NOT NULL,
	"expires_at" timestamp NOT NULL,
	"created_at" timestamp DEFAULT now() NOT NULL,
	"updated_at" timestamp DEFAULT now() NOT NULL
);
--> statement-breakpoint
ALTER TABLE "account" ADD CONSTRAINT "account_user_id_user_id_fk" FOREIGN KEY ("user_id") REFERENCES "public"."user"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "arvores" ADD CONSTRAINT "arvores_criado_por_user_id_fk" FOREIGN KEY ("criado_por") REFERENCES "public"."user"("id") ON DELETE restrict ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "auditoria" ADD CONSTRAINT "auditoria_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "auditoria" ADD CONSTRAINT "auditoria_usuario_id_user_id_fk" FOREIGN KEY ("usuario_id") REFERENCES "public"."user"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "documentos" ADD CONSTRAINT "documentos_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "documentos" ADD CONSTRAINT "documentos_pessoa_id_pessoas_id_fk" FOREIGN KEY ("pessoa_id") REFERENCES "public"."pessoas"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "documentos" ADD CONSTRAINT "documentos_criado_por_user_id_fk" FOREIGN KEY ("criado_por") REFERENCES "public"."user"("id") ON DELETE restrict ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "eventos" ADD CONSTRAINT "eventos_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "eventos" ADD CONSTRAINT "eventos_pessoa_id_pessoas_id_fk" FOREIGN KEY ("pessoa_id") REFERENCES "public"."pessoas"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "eventos" ADD CONSTRAINT "eventos_uniao_id_unioes_id_fk" FOREIGN KEY ("uniao_id") REFERENCES "public"."unioes"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "eventos" ADD CONSTRAINT "eventos_documento_id_documentos_id_fk" FOREIGN KEY ("documento_id") REFERENCES "public"."documentos"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "eventos" ADD CONSTRAINT "eventos_criado_por_user_id_fk" FOREIGN KEY ("criado_por") REFERENCES "public"."user"("id") ON DELETE restrict ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "filiacoes" ADD CONSTRAINT "filiacoes_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "filiacoes" ADD CONSTRAINT "filiacoes_filho_id_pessoas_id_fk" FOREIGN KEY ("filho_id") REFERENCES "public"."pessoas"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "filiacoes" ADD CONSTRAINT "filiacoes_pai_id_pessoas_id_fk" FOREIGN KEY ("pai_id") REFERENCES "public"."pessoas"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "filiacoes" ADD CONSTRAINT "filiacoes_mae_id_pessoas_id_fk" FOREIGN KEY ("mae_id") REFERENCES "public"."pessoas"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "filiacoes" ADD CONSTRAINT "filiacoes_criado_por_user_id_fk" FOREIGN KEY ("criado_por") REFERENCES "public"."user"("id") ON DELETE restrict ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "membros_arvore" ADD CONSTRAINT "membros_arvore_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "membros_arvore" ADD CONSTRAINT "membros_arvore_usuario_id_user_id_fk" FOREIGN KEY ("usuario_id") REFERENCES "public"."user"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "pessoas" ADD CONSTRAINT "pessoas_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "pessoas" ADD CONSTRAINT "pessoas_criado_por_user_id_fk" FOREIGN KEY ("criado_por") REFERENCES "public"."user"("id") ON DELETE restrict ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "session" ADD CONSTRAINT "session_user_id_user_id_fk" FOREIGN KEY ("user_id") REFERENCES "public"."user"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "unioes" ADD CONSTRAINT "unioes_arvore_id_arvores_id_fk" FOREIGN KEY ("arvore_id") REFERENCES "public"."arvores"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "unioes" ADD CONSTRAINT "unioes_pessoa1_id_pessoas_id_fk" FOREIGN KEY ("pessoa1_id") REFERENCES "public"."pessoas"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "unioes" ADD CONSTRAINT "unioes_pessoa2_id_pessoas_id_fk" FOREIGN KEY ("pessoa2_id") REFERENCES "public"."pessoas"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "unioes" ADD CONSTRAINT "unioes_criado_por_user_id_fk" FOREIGN KEY ("criado_por") REFERENCES "public"."user"("id") ON DELETE restrict ON UPDATE no action;--> statement-breakpoint
CREATE INDEX "account_userId_idx" ON "account" USING btree ("user_id");--> statement-breakpoint
CREATE INDEX "idx_arvores_criado_por" ON "arvores" USING btree ("criado_por");--> statement-breakpoint
CREATE INDEX "idx_auditoria_arvore" ON "auditoria" USING btree ("arvore_id","criado_em");--> statement-breakpoint
CREATE INDEX "idx_auditoria_usuario" ON "auditoria" USING btree ("usuario_id");--> statement-breakpoint
CREATE INDEX "idx_documentos_arvore" ON "documentos" USING btree ("arvore_id");--> statement-breakpoint
CREATE INDEX "idx_documentos_pessoa" ON "documentos" USING btree ("pessoa_id");--> statement-breakpoint
CREATE INDEX "idx_eventos_arvore" ON "eventos" USING btree ("arvore_id");--> statement-breakpoint
CREATE INDEX "idx_eventos_pessoa" ON "eventos" USING btree ("pessoa_id","data");--> statement-breakpoint
CREATE INDEX "idx_eventos_uniao" ON "eventos" USING btree ("uniao_id");--> statement-breakpoint
CREATE INDEX "idx_eventos_documento" ON "eventos" USING btree ("documento_id");--> statement-breakpoint
CREATE INDEX "idx_filiacoes_arvore" ON "filiacoes" USING btree ("arvore_id");--> statement-breakpoint
CREATE INDEX "idx_filiacoes_filho" ON "filiacoes" USING btree ("filho_id");--> statement-breakpoint
CREATE INDEX "idx_filiacoes_pai" ON "filiacoes" USING btree ("pai_id");--> statement-breakpoint
CREATE INDEX "idx_filiacoes_mae" ON "filiacoes" USING btree ("mae_id");--> statement-breakpoint
CREATE INDEX "idx_membros_arvore_usuario" ON "membros_arvore" USING btree ("usuario_id");--> statement-breakpoint
CREATE INDEX "idx_pessoas_arvore" ON "pessoas" USING btree ("arvore_id");--> statement-breakpoint
CREATE INDEX "session_userId_idx" ON "session" USING btree ("user_id");--> statement-breakpoint
CREATE UNIQUE INDEX "uq_unioes_par" ON "unioes" USING btree ("pessoa1_id","pessoa2_id");--> statement-breakpoint
CREATE INDEX "idx_unioes_arvore" ON "unioes" USING btree ("arvore_id");--> statement-breakpoint
CREATE INDEX "idx_unioes_pessoa2" ON "unioes" USING btree ("pessoa2_id");--> statement-breakpoint
CREATE INDEX "verification_identifier_idx" ON "verification" USING btree ("identifier");