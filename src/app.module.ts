import { Module } from '@nestjs/common';
import {ConfigModule, ConfigService} from "@nestjs/config";
import {TypeOrmModule} from "@nestjs/typeorm";
import { AuthModule } from './auth/auth.module';

@Module({
  imports: [
      ConfigModule.forRoot({isGlobal: true}),
      TypeOrmModule.forRootAsync({
        imports: [ConfigModule],
        inject: [ConfigService],
        useFactory: (configService: ConfigService) => ({
          type: "postgres",
          host: configService.getOrThrow("POSTGRES_HOST"),
          port: configService.getOrThrow("POSTGRES_PORT"),
          username: configService.getOrThrow('POSTGRES_USERNAME'),
          password: configService.getOrThrow("POSTGRES_PASSWORD"),
          database: configService.getOrThrow("POSTGRES_DATABASE"),
          autoLoadEntities: true,
          synchronize: true
        })
      }),
      AuthModule
  ],
  controllers: [],
  providers: [],
})
export class AppModule {}
